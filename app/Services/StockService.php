<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Staff stock receiving - extracted verbatim from
 * App\Http\Controllers\Staff\InventoryController::index() (Phase 3, Step 1).
 *
 * The controller keeps the HTTP concerns: reading the POST fields, the
 * action dispatch (receive / delete) and the redirect/flash.
 * This service owns the receiving domain logic:
 *
 *  - resolveExpiry(): the item-type CASE lookup that makes first aid items
 *    ignore the posted expiry field (batch expiry tracking). The lookup
 *    still runs for every POST with a selected item - exactly like the
 *    original controller - and the posted value is only read/trimmed for
 *    medicine items, so even a malformed posted value behaves as before.
 *
 *  - receive(): the four independent receive validations (medicine selected,
 *    min 3, expiry not in the past, date_received strtotime-able), then the
 *    manual transaction (beginTransaction / commit / rollBack) holding the
 *    batch-merge query (COALESCE identity match, newest first) that either
 *    UPDATEs quantity or INSERTs a new batch, the stock_movements IN row and
 *    the audit row.
 *
 * Error strings, SQL text and the catch semantics are byte-identical to the
 * controller's originals: any \Throwable rolls back (when the transaction is
 * still open) and surfaces the fixed 'Stock could not be added. Please try
 * again.' form error - never an exception. The success path returns a flag
 * and the controller builds the redirect (response formatting stays in HTTP).
 *
 * Only $request->user()->id was replaced: the controller resolves the
 * authenticated staff id once and passes it in as $staffId.
 */
class StockService
{
    /**
     * Resolve the expiry to record for a receive attempt.
     *
     * Runs the item-type CASE lookup (first_aid items never track expiry)
     * and, for medicine items only, trims the raw posted value and maps an
     * empty/'0' string to null - the original `?: null` semantics.
     *
     * @param  int  $medicineId  selected medicine (medicine_id), 0 when none
     * @param  array|string|null  $postedExpiry  raw expiry_date post value
     */
    public function resolveExpiry(int $medicineId, array|string|null $postedExpiry): ?string
    {
        if ($medicineId <= 0) {
            return null;
        }

        $itemTypeRow = DB::selectOne(
            'SELECT CASE WHEN c.type = "first_aid" THEN "first_aid" ELSE "medicine" END AS item_type
             FROM medicines m
             LEFT JOIN medicine_categories c ON c.id = m.category_id
             WHERE m.id = ? LIMIT 1',
            [$medicineId],
        );
        $itemType = ($itemTypeRow->item_type ?? null) ?: 'medicine';

        if ($itemType !== 'first_aid') {
            return trim($postedExpiry ?? '') ?: null;
        }

        return null;
    }

    /**
     * Run the stock receiving pipeline: four validations, then the manual
     * transaction (batch merge, stock movement log, audit row).
     *
     * Parameter names mirror the controller's original locals so the body
     * reads as a verbatim move.
     *
     * @param  int  $mid  selected medicine (medicine_id)
     * @param  string  $batchNo  trimmed batch_no ('' when absent)
     * @param  string  $lotNo  trimmed lot_no ('' when absent)
     * @param  int|null  $supId  supplier_id or null when absent/0
     * @param  int  $qty  posted quantity, already cast by the controller
     * @param  string|null  $expiry  resolved expiry (see resolveExpiry)
     * @param  string  $received  trimmed date_received, defaulted to today
     * @param  float|null  $unitCost  parsed unit_cost or null
     * @param  int  $staffId  authenticated staff user id ($request->user()->id)
     * @return array{success: bool, errors: array<int, string>}
     */
    public function receive(
        int $mid,
        string $batchNo,
        string $lotNo,
        ?int $supId,
        int $qty,
        ?string $expiry,
        string $received,
        ?float $cost,
        int $staffId,
    ): array {
        $errors = [];

        if ($mid <= 0) {
            $errors[] = 'Please select a medicine or first aid item.';
        }
        if ($qty < 3) {
            $errors[] = 'Quantity must be at least 3 for stock receiving.';
        }
        if (($expiry !== null) && strtotime($expiry) < strtotime('today')) {
            $errors[] = 'Expiry date cannot be in the past.';
        }
        if (strtotime($received) === false) {
            $errors[] = 'Date received is invalid.';
        }

        if ($errors) {
            return ['success' => false, 'errors' => $errors];
        }

        DB::beginTransaction();
        try {
            $existingBatch = DB::selectOne(
                'SELECT id, quantity FROM medicine_batches
                 WHERE medicine_id = ?
                   AND COALESCE(batch_no, "") = ?
                   AND COALESCE(lot_no, "") = ?
                   AND COALESCE(expiry_date, "") = ?
                   AND COALESCE(supplier_id, 0) = ?
                 ORDER BY created_at DESC LIMIT 1',
                [
                    $mid,
                    $batchNo !== '' ? $batchNo : '',
                    $lotNo !== '' ? $lotNo : '',
                    $expiry !== null ? $expiry : '',
                    $supId ?? 0,
                ],
            );

            if ($existingBatch) {
                $bid = (int) $existingBatch->id;
                DB::update(
                    'UPDATE medicine_batches SET quantity = quantity + ?, date_received = ?, unit_cost = COALESCE(?, unit_cost) WHERE id = ?',
                    [$qty, $received, $cost, $bid],
                );
                DB::insert(
                    'INSERT INTO stock_movements (medicine_id, batch_id, type, quantity, reason, ref_type, performed_by)
                       VALUES (?,?, "IN", ?, "Stock received", "stock_in", ?)',
                    [$mid, $bid, $qty, $staffId],
                );
                AuditLogger::log(
                    $staffId,
                    'stock_in',
                    'medicine_batches',
                    $bid,
                    "Added $qty units to batch #$bid (medicine #$mid)",
                );
            } else {
                $bid = DB::table('medicine_batches')->insertGetId([
                    'medicine_id' => $mid,
                    'batch_no' => $batchNo !== '' ? $batchNo : null,
                    'lot_no' => $lotNo !== '' ? $lotNo : null,
                    'supplier_id' => $supId,
                    'quantity' => $qty,
                    'expiry_date' => $expiry,
                    'date_received' => $received,
                    'unit_cost' => $cost,
                ]);
                DB::insert(
                    'INSERT INTO stock_movements (medicine_id, batch_id, type, quantity, reason, ref_type, performed_by)
                       VALUES (?,?, "IN", ?, "Stock received", "stock_in", ?)',
                    [$mid, $bid, $qty, $staffId],
                );
                AuditLogger::log(
                    $staffId,
                    'stock_in',
                    'medicine_batches',
                    $bid,
                    "Received $qty units (medicine #$mid)",
                );
            }
            DB::commit();

            return ['success' => true, 'errors' => []];
        } catch (\Throwable $ex) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $errors[] = 'Stock could not be added. Please try again.';

            return ['success' => false, 'errors' => $errors];
        }
    }
}

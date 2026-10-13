<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Equipment stock receiving - the equipment half of the unified Inventory
 * page's Stock In action.
 *
 * The medicine / first aid path is untouched: it still runs through
 * App\Services\StockService (batch merge, expiry tracking, stock_movements
 * IN row). Equipment never had batches, lot numbers or expiry dates, so it
 * keeps its own quantity counters - equipment.total_qty / available_qty,
 * the very fields EquipmentLoanService moves on issue and return - and this
 * service only ever moves those.
 *
 * There is deliberately NO parallel equipment stock ledger: a receive either
 * creates the equipment row (new item) or tops up the existing one, exactly
 * like the admin equipment form edits the same row. The transaction is
 * recorded through the app's shared audit mechanism (AuditLogger), the same
 * channel the medicine path and the loan issue/return paths write to.
 *
 * The four stock-in fields the medicine path stores on medicine_batches
 * (supplier, unit cost, asset id, date received) are stored on the equipment
 * row itself - supplier_id, unit_cost, asset_id and last_received - so a
 * restock never duplicates an inventory record.
 *
 * Availability: received units join available_qty immediately, so a freshly
 * stocked item is borrowable at once. condition_note is informational, as it
 * already is on the admin equipment form - the loan pipeline keys off
 * available_qty / is_active only and is left exactly as it was.
 */
class EquipmentStockService
{
    /** Condition values the Stock In modal offers (stored lowercase, slug form). */
    public const CONDITIONS = ['good', 'needs_repair', 'damaged'];

    /**
     * Store one equipment stock-in: validate, then create or top up the
     * equipment row inside a transaction.
     *
     * @param  int  $equipmentId  selected equipment item, 0 when adding a new one
     * @param  string  $newName  trimmed new-equipment name ('' when restocking)
     * @param  string  $newDescription  trimmed notes (brand / model / details)
     * @param  int  $qty  posted quantity, already cast by the controller
     * @param  int|null  $supId  supplier_id or null when absent/0
     * @param  float|null  $unitCost  parsed unit_cost or null
     * @param  string  $received  trimmed date_received, defaulted to today
     * @param  string  $condition  normalized condition slug (see CONDITIONS)
     * @param  string  $assetId  trimmed asset id / serial number ('' when absent)
     * @param  int  $staffId  authenticated staff user id ($request->user()->id)
     * @param  string  $receiptToken  per-render Stock In idempotency token; ''
     *                                (the default) disables receipt tracking
     * @return array{success: bool, duplicate: bool, receipt_id: int, errors: array<int, string>, equipment_id: int}
     */
    public function receive(
        int $equipmentId,
        string $newName,
        string $newDescription,
        int $qty,
        ?int $supId,
        ?float $unitCost,
        string $received,
        string $condition,
        string $assetId,
        int $staffId,
        string $receiptToken = '',
    ): array {
        $errors = [];

        if ($equipmentId <= 0 && $newName === '') {
            $errors[] = 'Please select an equipment item or enter a new equipment name.';
        }
        if ($qty < 1) {
            $errors[] = 'Quantity must be at least 1 for equipment stock.';
        }
        if (strtotime($received) === false) {
            $errors[] = 'Date received is invalid.';
        }

        $existing = null;
        if ($equipmentId > 0) {
            $existing = DB::table('equipment')->where('id', $equipmentId)->limit(1)->first();
            if (! $existing) {
                $errors[] = 'Selected equipment could not be found.';
            }
        }

        if ($errors) {
            return ['success' => false, 'duplicate' => false, 'receipt_id' => 0, 'errors' => $errors, 'equipment_id' => $equipmentId];
        }

        $receiptToken = trim($receiptToken);
        if ($receiptToken !== '' && DB::table('stock_receipts')->where('token', $receiptToken)->exists()) {
            return ['success' => false, 'duplicate' => true, 'receipt_id' => 0, 'errors' => [], 'equipment_id' => $equipmentId];
        }

        $condition = in_array($condition, self::CONDITIONS, true) ? $condition : null;

        try {
            [$equipmentId, $receiptId] = DB::transaction(function () use (
                $existing,
                $equipmentId,
                $newName,
                $newDescription,
                $qty,
                $supId,
                $unitCost,
                $received,
                $condition,
                $assetId,
                $staffId,
                $receiptToken,
            ) {
                // The receipt row is claimed FIRST, inside the very same
                // transaction as the counter update: the stock increase and
                // its once-only receipt commit or roll back together, so
                // neither a notification failure nor a retried request can
                // double-count.
                $receiptId = 0;
                if ($receiptToken !== '') {
                    $receiptId = (int) DB::table('stock_receipts')->insertGetId([
                        'token' => $receiptToken,
                        'item_type' => 'equipment',
                        'item_id' => $equipmentId > 0 ? $equipmentId : 0,
                        'quantity' => $qty,
                        'supplier_id' => $supId,
                        'staff_id' => $staffId,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }

                if ($existing) {
                    // Restock: top up the SAME row (never a duplicate record)
                    // and refresh the stock-in reference fields that were
                    // actually posted, leaving the rest as they were.
                    $updates = [
                        'total_qty' => (int) $existing->total_qty + $qty,
                        'available_qty' => (int) $existing->available_qty + $qty,
                        'last_received' => $received,
                    ];
                    if ($condition !== null) {
                        $updates['condition_note'] = $condition;
                    }
                    if ($supId !== null) {
                        $updates['supplier_id'] = $supId;
                    }
                    if ($unitCost !== null) {
                        $updates['unit_cost'] = $unitCost;
                    }
                    if ($assetId !== '') {
                        $updates['asset_id'] = $assetId;
                    }
                    if ($newDescription !== '') {
                        $updates['description'] = $newDescription;
                    }

                    DB::table('equipment')->where('id', $equipmentId)->update($updates);

                    if ($receiptId > 0) {
                        DB::table('stock_receipts')->where('id', $receiptId)->update(['item_id' => $equipmentId]);
                    }

                    return [$equipmentId, $receiptId];
                }

                // New item: the quantity it is stocked with is both its total
                // and its available stock, so it is loanable straight away.
                $equipmentId = (int) DB::table('equipment')->insertGetId([
                    'name' => $newName,
                    'description' => $newDescription !== '' ? $newDescription : null,
                    'total_qty' => $qty,
                    'available_qty' => $qty,
                    'condition_note' => $condition ?? 'good',
                    'supplier_id' => $supId,
                    'unit_cost' => $unitCost,
                    'asset_id' => $assetId !== '' ? $assetId : null,
                    'last_received' => $received,
                    'is_active' => 1,
                ]);

                if ($receiptId > 0) {
                    DB::table('stock_receipts')->where('id', $receiptId)->update(['item_id' => $equipmentId]);
                }

                return [$equipmentId, $receiptId];
            });
        } catch (\Throwable $ex) {
            return [
                'success' => false,
                'duplicate' => false,
                'receipt_id' => 0,
                'errors' => ['Equipment stock could not be added. Please try again.'],
                'equipment_id' => $equipmentId,
            ];
        }

        AuditLogger::log(
            $staffId,
            'stock_in',
            'equipment',
            $equipmentId,
            $existing
                ? "Added $qty units to equipment #$equipmentId"
                : "Received $qty units (new equipment #$equipmentId)",
        );

        return ['success' => true, 'duplicate' => false, 'receipt_id' => $receiptId, 'errors' => [], 'equipment_id' => $equipmentId];
    }

    /**
     * Display label for a stored condition slug.
     *
     * The column is free text (the admin equipment form writes whatever the
     * staff types), so legacy values such as 'fair' or 'poor' pass through
     * prettified rather than being remapped.
     */
    public static function conditionLabel(?string $condition): string
    {
        $condition = trim((string) $condition);

        return $condition === '' ? '—' : ucwords(str_replace('_', ' ', $condition));
    }
}

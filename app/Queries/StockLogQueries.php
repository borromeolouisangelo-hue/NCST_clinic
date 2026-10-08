<?php

namespace App\Queries;

use App\Services\StockUnits;
use Illuminate\Support\Facades\DB;

/**
 * Staff deduction-log reads - extracted verbatim from
 * App\Http\Controllers\Staff\DeductionLogsController::index() (Phase 4, Batch 1).
 *
 * The controller keeps HTTP concerns (the ?q= / ?status= reads) and view
 * rendering. This class owns all page reads, run in the original order:
 *
 *  - the OUT-movement list query (four-LIKE search builder over
 *    medicine/patient/batch, the ['dispensed','rejected'] status whitelist -
 *    any other value adds NO filter - and the LIMIT 200 tail),
 *  - withStockContext(): the read-only before/after figure reconstruction
 *    from movement history (feature 1), including its three follow-up
 *    reads and the try/catch shim on the movements query,
 *  - the two global OUT-movement counters (original lines 43-44).
 *
 * Nothing here writes to the database - same as the original page (this
 * route never logs audit rows).
 */
class StockLogQueries
{
    /**
     * All page data for the resolved search/status inputs.
     *
     * @param  string  $search  trimmed ?q= value
     * @param  string|array|null  $statusFilter  raw ?status= value (array
     *                                           input simply fails the strict whitelist,
     *                                           exactly like the original in_array check)
     * @return array{deductions: array<int, object>, totalDeductions: int, todayDeductions: int}
     */
    public function overview(string $search, string|array|null $statusFilter): array
    {
        $sql = "SELECT sm.id AS movement_id, sm.type, sm.quantity AS deducted_qty,
                sm.batch_id, sm.reason, sm.ref_type, sm.ref_id, sm.created_at AS deducted_at,
                m.id AS medicine_id, m.name AS medicine_name, m.unit,
                b.batch_no, b.lot_no,
                CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                r.status AS request_status, r.reason AS request_reason
         FROM stock_movements sm
         JOIN medicines m ON m.id = sm.medicine_id
         LEFT JOIN medicine_batches b ON b.id = sm.batch_id
         LEFT JOIN medicine_requests r ON r.id = sm.ref_id AND sm.ref_type = 'dispense'
         LEFT JOIN profiles p ON p.user_id = r.user_id
         WHERE sm.type = 'OUT'";

        $params = [];
        if ($search !== '') {
            $sql .= ' AND (m.name LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR b.batch_no LIKE ?)';
            array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
        }
        if (in_array($statusFilter, ['dispensed', 'rejected'], true)) {
            $sql .= ' AND r.status = ?';
            $params[] = $statusFilter;
        }

        $sql .= ' ORDER BY sm.created_at DESC LIMIT 200';
        $deductions = DB::select($sql, $params);

        // Feature: previous / deducted / resulting / total-after figures.
        $deductions = self::withStockContext($deductions);

        // Summary stats (original 43-44).
        $totalDeductions = (int) DB::selectOne("SELECT COUNT(*) AS c FROM stock_movements WHERE type = 'OUT'")->c;
        $todayDeductions = (int) DB::selectOne("SELECT COUNT(*) AS c FROM stock_movements WHERE type = 'OUT' AND DATE(created_at) = CURDATE()")->c;

        return [
            'deductions' => $deductions,
            'totalDeductions' => $totalDeductions,
            'todayDeductions' => $todayDeductions,
        ];
    }

    /**
     * Attach the before/after figures each deduction log row has to show.
     *
     * The schema is frozen - stock_movements stores no running balance - so
     * every figure is reconstructed from the movement history itself and
     * anchored on the batches that still exist:
     *
     *  - piece state  = cumulative OUT quantity mapped through the package
     *                    size of the unit (12 for tablets, 50 for boxes; the
     *                    non-piece units have no package and show "—"),
     *                    see App\Services\StockUnits::pieces();
     *  - batch total  = the batch's current quantity minus the net effect of
     *                    every later movement on that same batch (null when
     *                    the batch was deleted since);
     *  - total after  = the medicine's current stock (same definition as the
     *                    inventory page: positive batches only) minus the net
     *                    effect of every later movement on that medicine;
     *  - total before = total after + the quantity this movement removed.
     *
     * Nothing is written; the figures are read-only display values.
     *
     * @param  array<int, object>  $rows
     * @return array<int, object>
     */
    private static function withStockContext(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $medicineIds = array_values(array_unique(array_map(fn ($r) => (int) $r->medicine_id, $rows)));
        $inMedicines = implode(',', array_fill(0, count($medicineIds), '?'));

        // Package size per medicine (null = not piece-tracked).
        $packages = [];
        foreach (DB::select("SELECT id, unit FROM medicines WHERE id IN ($inMedicines)", $medicineIds) as $m) {
            $packages[(int) $m->id] = StockUnits::packageSize($m->unit);
        }

        // Current medicine stock (inventory definition: positive batches).
        $totals = [];
        foreach (DB::select(
            "SELECT medicine_id, COALESCE(SUM(quantity), 0) AS total_qty
               FROM medicine_batches
              WHERE quantity > 0 AND medicine_id IN ($inMedicines)
              GROUP BY medicine_id",
            $medicineIds,
        ) as $r) {
            $totals[(int) $r->medicine_id] = (int) $r->total_qty;
        }

        // Every movement of those medicines, oldest first.
        try {
            $movements = DB::select(
                "SELECT id, medicine_id, batch_id, type, quantity
                   FROM stock_movements
                  WHERE medicine_id IN ($inMedicines)
                  ORDER BY id ASC",
                $medicineIds,
            );
        } catch (\Throwable $ex) {
            $movements = [];
        }

        $netAfter = [];      // movement id -> net effect of every LATER movement (medicine scope)
        $batchNetAfter = []; // movement id -> same, restricted to one batch
        $outBefore = [];     // movement id -> cumulative OUT quantity before it (medicine scope)

        $byMedicine = [];
        foreach ($movements as $mv) {
            $byMedicine[(int) $mv->medicine_id][] = $mv;
        }

        foreach ($byMedicine as $list) {
            // Descending: net effect of everything strictly after each row.
            $net = 0;
            $batchNet = [];
            for ($i = count($list) - 1; $i >= 0; $i--) {
                $mv = $list[$i];
                $delta = strtoupper((string) $mv->type) === 'IN' ? (int) $mv->quantity : -(int) $mv->quantity;
                $netAfter[(int) $mv->id] = $net;

                $bid = $mv->batch_id !== null ? (int) $mv->batch_id : null;
                if ($bid !== null) {
                    $batchNetAfter[(int) $mv->id] = $batchNet[$bid] ?? 0;
                    $batchNet[$bid] = ($batchNet[$bid] ?? 0) + $delta;
                }
                $net += $delta;
            }

            // Ascending: cumulative OUT before each row.
            $out = 0;
            foreach ($list as $mv) {
                $outBefore[(int) $mv->id] = $out;
                if (strtoupper((string) $mv->type) === 'OUT') {
                    $out += (int) $mv->quantity;
                }
            }
        }

        // Current quantity of every batch the page references.
        $batchIds = array_values(array_unique(array_filter(array_map(
            fn ($r) => $r->batch_id !== null ? (int) $r->batch_id : 0,
            $rows,
        ))));
        $batchQty = [];
        if ($batchIds !== []) {
            $inBatches = implode(',', array_fill(0, count($batchIds), '?'));
            foreach (DB::select("SELECT id, quantity FROM medicine_batches WHERE id IN ($inBatches)", $batchIds) as $b) {
                $batchQty[(int) $b->id] = (int) $b->quantity;
            }
        }

        foreach ($rows as $row) {
            $mid = (int) $row->medicine_id;
            $movementId = (int) $row->movement_id;
            $deducted = (int) $row->deducted_qty;
            $packageSize = $packages[$mid] ?? null;

            $outB = $outBefore[$movementId] ?? 0;
            $row->prev_pieces = $packageSize !== null ? StockUnits::pieces($outB, $packageSize) : null;
            $row->res_pieces = $packageSize !== null ? StockUnits::pieces($outB + $deducted, $packageSize) : null;
            $row->package_size = $packageSize;

            $later = (int) ($netAfter[$movementId] ?? 0);
            $row->total_after = isset($totals[$mid])
                ? $totals[$mid] - $later
                : null;
            $row->total_before = $row->total_after !== null ? $row->total_after + $deducted : null;

            $bid = $row->batch_id !== null ? (int) $row->batch_id : null;
            if ($bid !== null && array_key_exists($bid, $batchQty)) {
                $row->prev_qty = $batchQty[$bid] - (int) ($batchNetAfter[$movementId] ?? 0) + $deducted;
                $row->res_qty = $batchQty[$bid] - (int) ($batchNetAfter[$movementId] ?? 0);
            } else {
                $row->prev_qty = null;
                $row->res_qty = null;
            }
        }

        return $rows;
    }
}

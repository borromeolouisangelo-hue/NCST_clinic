<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Staff deduction-log reads - the restored StockLogQueries (the original
 * was deleted in the post-migration cleanup, commit 4c92230) rebuilt for
 * the Inventory -> View Deduction Logs feature, which renders inside the
 * Transaction History page's Inventory section (/staff/history?tab=
 * inventory) rather than as a standalone screen.
 *
 * The controller owns HTTP concerns (?q= / ?type= / ?filter= / ?page=
 * reads, whitelist collapsing, view rendering); this class owns every page
 * read:
 *
 *  - the unified deduction event list: ONE UNION over both stock systems -
 *    medicine OUT movements (stock_movements, exactly what
 *    deduct_stock_trace() and StockUnits::depleteOpened() write, so
 *    individual-piece dispenses and container/roll depletions appear and
 *    nothing else) and equipment availability events (equipment_loans:
 *    the borrow as -quantity, the completed return as +quantity, so a
 *    temporary loan is never presented as a permanent loss and its
 *    restoration is visible too). Stock IN rows are stock receiving, not
 *    deductions, and stay excluded; a rejected or uncompleted request
 *    writes no movement, so it can never appear either.
 *
 *  - DB-level filtering (item-name LIKE, Medicine/Equipment type, the
 *    all|today|week|month window in the same CURDATE()/SUBDATE()
 *    spelling StaffHistory::build() uses for consultations), newest-first
 *    ORDER BY on the actual event timestamp, and LIMIT/OFFSET pagination -
 *    the WHERE, ORDER BY and LIMIT all run in SQL, never in PHP.
 *
 *  - withStockContext(): the stock-before / stock-remaining figures every
 *    row must show, reconstructed from the movement and loan history alone
 *    (the schema stores no running balance - the deleted implementation
 *    reconstructed the same way from the same tables).
 *
 *  - the two global OUT counters on the metric cards (medicine OUTs plus
 *    equipment borrows; they ignore the active search/filter, exactly
 *    like the original page's counters).
 *
 * Nothing here writes to the database - this route never logs audit rows.
 */
class StockLogQueries
{
    /** Rows per page (the logs page paginates; the original LIMIT 200 did not). */
    public const PER_PAGE = 20;

    /**
     * One page of deduction events for the resolved search/type/date inputs.
     *
     * This is the read behind the Transaction History page's Inventory
     * section: DB-level filtering, newest event first, LIMIT/OFFSET
     * pagination, plus the total that drives the page nav.
     *
     * @param  string  $search  trimmed ?q= value (item-name LIKE)
     * @param  string  $typeFilter  whitelisted ?type= value (all|medicine|equipment)
     * @param  string  $dateFilter  whitelisted ?filter= value (all|today|week|month)
     * @param  int  $page  1-based page number
     * @return array{events: array<int, object>, total: int}
     */
    public function events(string $search, string $typeFilter, string $dateFilter, int $page): array
    {
        $perPage = self::PER_PAGE;
        $offset = max(0, ($page - 1)) * $perPage;

        // The equipment branches need the equipment tables; isolated test
        // fixtures (and the pre-equipment schema) may not carry them, so a
        // failed run retries as the medicine-only union - the outer WHERE,
        // ORDER BY and column list are identical, so the same params apply.
        $withEquipment = true;
        try {
            $events = DB::select(
                self::pageSql($search, $typeFilter, $dateFilter, true, $perPage, $offset),
                self::filterParams($search, $typeFilter, $dateFilter),
            );
        } catch (Throwable $ex) {
            $withEquipment = false;
            $events = DB::select(
                self::pageSql($search, $typeFilter, $dateFilter, false, $perPage, $offset),
                self::filterParams($search, $typeFilter, $dateFilter),
            );
        }

        $events = self::withStockContext($events);

        try {
            $total = (int) DB::selectOne(
                self::countSql($search, $typeFilter, $dateFilter, $withEquipment),
                self::filterParams($search, $typeFilter, $dateFilter),
            )->c;
        } catch (Throwable $ex) {
            $total = 0;
        }

        return ['events' => $events, 'total' => $total];
    }

    // ------------------------------------------------------------------
    // Event list (DB-level filter / order / paginate)
    // ------------------------------------------------------------------

    /**
     * The event UNION: one row per real deduction event, signed delta.
     *
     * Medicine branch: OUT movements only. delta is NEGATIVE (-quantity),
     * event_at is the movement's created_at, event_id is the movement id.
     *
     * Equipment branches: a borrow (-quantity at borrowed_at, seq 0) and,
     * for completed loans, a return (+quantity, seq 1). Returns carry no
     * clock time in the schema (return_date is a DATE), so the event is
     * stamped at the END of its day: a loan's own borrow - a real clock
     * time - therefore always sorts before its return, even when both
     * happen on one day, and same-day events of other loans keep the
     * closest-to-truth order available (see withStockContext).
     *
     * Columns are deliberately generic: type_key drives the Type filter
     * and badge, item_id/event_id/event_seq identify the event for the
     * figure reconstruction, and detail/event_kind feed the last column
     * and the date rendering.
     */
    private static function unionSql(bool $withEquipment): string
    {
        $medicine = "SELECT 'medicine' AS type_key,
                m.name AS item_name,
                m.id AS item_id,
                m.unit AS unit,
                -sm.quantity AS delta,
                sm.created_at AS event_at,
                sm.id AS event_id,
                0 AS event_seq,
                COALESCE(sm.reason, '') AS detail,
                COALESCE(sm.ref_type, '') AS event_kind
         FROM stock_movements sm
         JOIN medicines m ON m.id = sm.medicine_id
         WHERE sm.type = 'OUT'";

        if (! $withEquipment) {
            return $medicine;
        }

        return $medicine.'
        UNION ALL
        SELECT \'equipment\', e.name, e.id, \'pieces\', -l.quantity,
               l.borrowed_at, l.id, 0, \'Loan issued\', \'loan\'
        FROM equipment_loans l
        JOIN equipment e ON e.id = l.equipment_id
        UNION ALL
        SELECT \'equipment\', e.name, e.id, \'pieces\', l.quantity,
               CONCAT(l.return_date, \' 23:59:59\'), l.id, 1, \'Equipment returned\', \'return\'
        FROM equipment_loans l
        JOIN equipment e ON e.id = l.equipment_id
        WHERE l.status = \'returned\' AND l.return_date IS NOT NULL';
    }

    /** The WHERE clause shared by the page and count queries (same params, same order). */
    private static function filterSql(string $search, string $typeFilter, string $dateFilter): string
    {
        $sql = ' WHERE 1 = 1';

        if ($search !== '') {
            $sql .= ' AND item_name LIKE ?';
        }
        if (in_array($typeFilter, ['medicine', 'equipment'], true)) {
            $sql .= ' AND type_key = ?';
        }
        if ($dateFilter === 'today') {
            $sql .= ' AND DATE(event_at) = CURDATE()';
        } elseif ($dateFilter === 'week') {
            $sql .= ' AND event_at >= SUBDATE(CURDATE(), 7)';
        } elseif ($dateFilter === 'month') {
            $sql .= ' AND event_at >= SUBDATE(CURDATE(), 30)';
        }

        return $sql;
    }

    /** Bindings for filterSql(), in the exact order the placeholders appear. */
    private static function filterParams(string $search, string $typeFilter, string $dateFilter): array
    {
        $params = [];
        if ($search !== '') {
            $params[] = '%'.$search.'%';
        }
        if (in_array($typeFilter, ['medicine', 'equipment'], true)) {
            $params[] = $typeFilter;
        }

        return $params;
    }

    /** The page query: union + filter, newest event first, LIMIT/OFFSET. */
    private static function pageSql(string $search, string $typeFilter, string $dateFilter, bool $withEquipment, int $perPage, int $offset): string
    {
        return 'SELECT * FROM ('.self::unionSql($withEquipment).') AS events'
            .self::filterSql($search, $typeFilter, $dateFilter)
            .' ORDER BY event_at DESC, event_id DESC, event_seq DESC'
            .' LIMIT '.max(1, $perPage).' OFFSET '.max(0, $offset);
    }

    /** The pagination count: the same union and filter, without ORDER BY / LIMIT. */
    private static function countSql(string $search, string $typeFilter, string $dateFilter, bool $withEquipment): string
    {
        return 'SELECT COUNT(*) AS c FROM ('.self::unionSql($withEquipment).') AS events'
            .self::filterSql($search, $typeFilter, $dateFilter);
    }

    // ------------------------------------------------------------------
    // Figure reconstruction
    // ------------------------------------------------------------------

    /**
     * Attach stock_before / stock_after to every listed event.
     *
     * The schema stores no running balance, so both figures are rebuilt
     * from the event history and anchored on the CURRENT stock - the same
     * technique the deleted implementation used for movements:
     *
     *  - Medicine: anchor = the medicine's current inventory stock
     *    (positive batches, the inventory page's definition; a fully
     *    depleted item contributes 0). stock_after = anchor - net effect
     *    of every LATER movement; stock_before = stock_after - delta
     *    (delta is negative, so before > after).
     *
     *  - Equipment: anchor = equipment.available_qty right now - the field
     *    EquipmentLoanService moves on issue/return. Every loan of the
     *    item contributes its borrow and (when completed) its return
     *    event; they are sorted chronologically per item and replayed
     *    backwards the same way. If an admin later edits total stock,
     *    figures shift by that edit - unavoidable without a stored
     *    balance, and exactly the approximation the movement replay makes.
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

        $medicineRows = [];
        $equipmentRows = [];
        foreach ($rows as $row) {
            if ($row->type_key === 'equipment') {
                $equipmentRows[] = $row;
            } else {
                $medicineRows[] = $row;
            }
        }

        self::medicineFigures($medicineRows);
        self::equipmentFigures($equipmentRows);

        return $rows;
    }

    /** Before/after totals for the medicine movements on this page. */
    private static function medicineFigures(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $medicineIds = array_values(array_unique(array_map(fn ($r) => (int) $r->item_id, $rows)));
        $inMedicines = implode(',', array_fill(0, count($medicineIds), '?'));

        // Current inventory stock per medicine (positive batches only).
        $totals = array_fill_keys($medicineIds, 0);
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
                "SELECT id, medicine_id, type, quantity
                   FROM stock_movements
                  WHERE medicine_id IN ($inMedicines)
                  ORDER BY id ASC",
                $medicineIds,
            );
        } catch (Throwable $ex) {
            $movements = [];
        }

        // Descending: net effect of everything strictly after each movement.
        $netAfter = [];
        $byMedicine = [];
        foreach ($movements as $mv) {
            $byMedicine[(int) $mv->medicine_id][] = $mv;
        }
        foreach ($byMedicine as $list) {
            $net = 0;
            for ($i = count($list) - 1; $i >= 0; $i--) {
                $mv = $list[$i];
                $netAfter[(int) $mv->id] = $net;
                $delta = strtoupper((string) $mv->type) === 'IN' ? (int) $mv->quantity : -(int) $mv->quantity;
                $net += $delta;
            }
        }

        foreach ($rows as $row) {
            $later = (int) ($netAfter[(int) $row->event_id] ?? 0);
            $row->stock_after = ($totals[(int) $row->item_id] ?? 0) - $later;
            $row->stock_before = $row->stock_after - (int) $row->delta;
        }
    }

    /** Before/after availability for the equipment events on this page. */
    private static function equipmentFigures(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $equipmentIds = array_values(array_unique(array_map(fn ($r) => (int) $r->item_id, $rows)));
        $inEquipment = implode(',', array_fill(0, count($equipmentIds), '?'));

        // Current availability - the stock loans move.
        $current = array_fill_keys($equipmentIds, 0);
        foreach (DB::select("SELECT id, available_qty FROM equipment WHERE id IN ($inEquipment)", $equipmentIds) as $r) {
            $current[(int) $r->id] = (int) $r->available_qty;
        }

        // Every loan of those items - the two events each completed loan contributes.
        try {
            $loans = DB::select(
                "SELECT id, equipment_id, quantity, status, borrowed_at, return_date
                   FROM equipment_loans
                  WHERE equipment_id IN ($inEquipment)
                  ORDER BY id ASC",
                $equipmentIds,
            );
        } catch (Throwable $ex) {
            $loans = [];
        }

        $byEquipment = [];
        foreach ($loans as $loan) {
            $qty = (int) $loan->quantity;
            $byEquipment[(int) $loan->equipment_id][] = [
                'id' => (int) $loan->id,
                'seq' => 0,
                'at' => (string) $loan->borrowed_at,
                'delta' => -$qty,
            ];
            if ($loan->status === 'returned' && $loan->return_date !== null && $loan->return_date !== '') {
                $byEquipment[(int) $loan->equipment_id][] = [
                    'id' => (int) $loan->id,
                    'seq' => 1,
                    'at' => $loan->return_date.' 23:59:59',
                    'delta' => $qty,
                ];
            }
        }

        // Chronological per item (returns at end-of-day, see unionSql),
        // then descending: net effect of everything strictly after each event.
        $netAfter = [];
        foreach ($byEquipment as $list) {
            usort($list, fn ($a, $b) => [$a['at'], $a['id'], $a['seq']] <=> [$b['at'], $b['id'], $b['seq']]);
            $net = 0;
            for ($i = count($list) - 1; $i >= 0; $i--) {
                $ev = $list[$i];
                $netAfter[$ev['id'].':'.$ev['seq']] = $net;
                $net += $ev['delta'];
            }
        }

        foreach ($rows as $row) {
            $key = (int) $row->event_id.':'.(int) $row->event_seq;
            $later = (int) ($netAfter[$key] ?? 0);
            $row->stock_after = ($current[(int) $row->item_id] ?? 0) - $later;
            $row->stock_before = $row->stock_after - (int) $row->delta;
        }
    }
}

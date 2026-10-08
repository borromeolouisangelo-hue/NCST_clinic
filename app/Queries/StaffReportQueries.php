<?php

namespace App\Queries;

use App\Services\ClinicTrend;
use Illuminate\Support\Facades\DB;

/**
 * Staff reports aggregates - extracted verbatim from
 * App\Http\Controllers\Staff\StaffReportsController::index() (Phase 4, Batch 1).
 *
 * The controller keeps HTTP concerns: the report_range() period parsing from
 * the query string, ShellContext and view rendering. This class owns the
 * page's data, run in the original execution order:
 *
 *  - the DATE() BETWEEN aggregate family shared with AdminReportQueries
 *    (consultation count, requested medicine units, medicine rows, borrowed
 *    equipment sum, equipment loan rows) - each with its ONLY_FULL_GROUP_BY
 *    GROUP BY accommodation,
 *  - the two unbounded chart queries (frequently requested / borrowed items)
 *    that intentionally IGNORE the period filter (original lines 50-66),
 *  - the 7/14/30-day ClinicTrend ranges plus the sidebar seed derived from
 *    range '7' (periodTotal / periodChange), and the literal $days = 7 the
 *    reference feeds renderTrend() with.
 *
 * Dead code: the $topPatients query (original lines 37-44) never reaches the
 * view - dropped, same precedent as Phase 27's $lowStock.
 *
 * The rows() mapper mirrors AdminReportQueries::rows() byte-for-byte; each
 * class stays self-contained (deduplication is a future cleanup, not a
 * behavior concern).
 */
class StaffReportQueries
{
    /**
     * All page data for the resolved [from, to] range.
     *
     * @return array{consCount: int, requestCount: int, medicineRequests: array<int, array>, loanCount: int, equipmentLoans: array<int, array>, requestedItems: array<int, array>, borrowedItems: array<int, array>, trendRanges: array<string, array>, days: int, periodTotal: mixed, periodChange: mixed}
     */
    public function overview(string $from, string $to): array
    {
        // Consultations in range (original lines 12-14)
        $consCount = (int) DB::scalar(
            'SELECT COUNT(*) FROM consultations WHERE DATE(consultation_date) BETWEEN ? AND ?',
            [$from, $to]
        );

        // Requested medicine units in range (original lines 16-18)
        $requestCount = (int) DB::scalar(
            'SELECT COALESCE(SUM(quantity),0) FROM medicine_requests WHERE DATE(requested_at) BETWEEN ? AND ?',
            [$from, $to]
        );

        // Medicine rows (original lines 20-27) - m.name in GROUP BY per the
        // Phase 27 ONLY_FULL_GROUP_BY accommodation (constant per group).
        $medicineRequests = $this->rows(DB::select(
            'SELECT m.name, r.reason, SUM(r.quantity) AS total
             FROM medicine_requests r JOIN medicines m ON m.id = r.medicine_id
             WHERE DATE(r.requested_at) BETWEEN ? AND ?
             GROUP BY r.medicine_id, r.reason, m.name ORDER BY total DESC',
            [$from, $to]
        ));

        // Borrowed equipment units in range (original lines 29-31)
        $loanCount = (int) DB::scalar(
            'SELECT COALESCE(SUM(quantity),0) FROM equipment_loans WHERE DATE(borrow_date) BETWEEN ? AND ?',
            [$from, $to]
        );

        // Equipment loan rows (original lines 33-35) - e.name same way.
        $equipmentLoans = $this->rows(DB::select(
            'SELECT e.name, l.notes AS reason, SUM(l.quantity) AS total
             FROM equipment_loans l JOIN equipment e ON e.id = l.equipment_id
             WHERE DATE(l.borrow_date) BETWEEN ? AND ?
             GROUP BY l.equipment_id, l.notes, e.name ORDER BY total DESC',
            [$from, $to]
        ));

        // $topPatients (original lines 37-44) never reaches the view - dropped.

        // --- Charts: mirror staff/dashboard.php exactly (original lines 50-66);
        // unbounded GROUP BYs already list the selected name columns. ---
        $requestedItems = $this->rows(DB::select(
            'SELECT m.name, SUM(mr.quantity) AS total_quantity
             FROM medicine_requests mr
             JOIN medicines m ON m.id = mr.medicine_id
             GROUP BY mr.medicine_id, m.name
             ORDER BY total_quantity DESC, m.name ASC
             LIMIT 6'
        ));
        $borrowedItems = $this->rows(DB::select(
            'SELECT e.name, SUM(el.quantity) AS total_quantity
             FROM equipment_loans el
             JOIN equipment e ON e.id = el.equipment_id
             GROUP BY el.equipment_id, e.name
             ORDER BY total_quantity DESC, e.name ASC
             LIMIT 6'
        ));

        // --- 7/14/30-day trend ranges + sidebar seed (original lines 68-75). ---
        $days = 7;
        $trendRanges = [
            '7' => ClinicTrend::range(7),
            '14' => ClinicTrend::range(14),
            '30' => ClinicTrend::range(30),
        ];
        $periodTotal = $trendRanges['7']['total'];
        $periodChange = $trendRanges['7']['change'];

        return [
            'consCount' => $consCount,
            'requestCount' => $requestCount,
            'medicineRequests' => $medicineRequests,
            'loanCount' => $loanCount,
            'equipmentLoans' => $equipmentLoans,
            'requestedItems' => $requestedItems,
            'borrowedItems' => $borrowedItems,
            'trendRanges' => $trendRanges,
            'days' => $days,
            'periodTotal' => $periodTotal,
            'periodChange' => $periodChange,
        ];
    }

    /**
     * Fetch rows as associative arrays (the reference's fetchAll() default
     * carries string keys; array_column() in the chart lines reads those).
     *
     * @param  array<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $rows): array
    {
        return array_map(static fn ($row): array => (array) $row, $rows);
    }
}

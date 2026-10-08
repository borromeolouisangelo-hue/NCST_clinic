<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/**
 * Admin reports aggregates - extracted verbatim from
 * App\Http\Controllers\Admin\AdminReportsController::index() (Phase 4, Batch 1).
 *
 * The controller keeps HTTP concerns: the report_range() period parsing from
 * the query string, ShellContext and view rendering. This class owns the
 * seven range aggregates (all scoped with DATE(col) BETWEEN ? AND ?), run in
 * the original execution order:
 *
 *  - consultation count, requested medicine units,
 *  - medicine request rows + chart series (GROUP BY carries m.name for the
 *    ONLY_FULL_GROUP_BY accommodation),
 *  - borrowed equipment sum, equipment loan rows + chart series,
 *  - certificate count.
 *
 * Rows are cast to arrays so the view's array_column()/array_map('intval')
 * chart building matches the reference's PDO FETCH_BOTH output exactly.
 *
 * The reference's $lowStock query (original lines 69-75) is computed but
 * never rendered - dropped, same precedent as Phase 22's $actions DISTINCT
 * query and Phase 25's $categoryOptions.
 */
class AdminReportQueries
{
    /**
     * All page aggregates for the resolved [from, to] range.
     *
     * @return array{consCount: int, requestCount: int, medicineRequests: array<int, array>, medicineChart: array<int, array>, loanCount: int, equipmentLoans: array<int, array>, equipmentChart: array<int, array>, certCount: int}
     */
    public function overview(string $from, string $to): array
    {
        // Consultations in range (original lines 12-15)
        $consCount = (int) DB::scalar(
            'SELECT COUNT(*) FROM consultations WHERE DATE(consultation_date) BETWEEN ? AND ?',
            [$from, $to]
        );

        // Requested medicine units in range (original lines 17-20)
        $requestCount = (int) DB::scalar(
            'SELECT COALESCE(SUM(quantity),0) FROM medicine_requests WHERE DATE(requested_at) BETWEEN ? AND ?',
            [$from, $to]
        );

        // Medicine usage rows + chart series (original lines 22-39).
        // GROUP BY additionally lists m.name: functionally dependent on the
        // PK m.id through the JOIN, so groups/rows are identical - listing it
        // keeps the statement valid under the ONLY_FULL_GROUP_BY session mode
        // Laravel opens MySQL with (same accommodation as DashboardController;
        // the reference PDO runs under the server default, which MariaDB does
        // not relax for cross-table functional dependencies).
        $medicineRequests = $this->rows(DB::select(
            'SELECT m.name, r.reason, SUM(r.quantity) AS total
             FROM medicine_requests r JOIN medicines m ON m.id = r.medicine_id
             WHERE DATE(r.requested_at) BETWEEN ? AND ?
             GROUP BY r.medicine_id, r.reason, m.name ORDER BY total DESC',
            [$from, $to]
        ));
        $medicineChart = $this->rows(DB::select(
            'SELECT m.name, SUM(r.quantity) AS total
             FROM medicine_requests r JOIN medicines m ON m.id = r.medicine_id
             WHERE DATE(r.requested_at) BETWEEN ? AND ?
             GROUP BY r.medicine_id, m.name ORDER BY total DESC',
            [$from, $to]
        ));

        // Borrowed equipment in range (original lines 41-44)
        $loanCount = (int) DB::scalar(
            'SELECT COALESCE(SUM(quantity),0) FROM equipment_loans WHERE DATE(borrow_date) BETWEEN ? AND ?',
            [$from, $to]
        );

        // Equipment loan rows + chart series (original lines 46-62) -
        // m.name-style GROUP BY addition identical to the medicine pair above
        // (e.name is constant per e.id = l.equipment_id; same-row guarantee).
        $equipmentLoans = $this->rows(DB::select(
            'SELECT e.name, l.notes AS reason, SUM(l.quantity) AS total
             FROM equipment_loans l JOIN equipment e ON e.id = l.equipment_id
             WHERE DATE(l.borrow_date) BETWEEN ? AND ?
             GROUP BY l.equipment_id, l.notes, e.name ORDER BY total DESC',
            [$from, $to]
        ));
        $equipmentChart = $this->rows(DB::select(
            'SELECT e.name, SUM(l.quantity) AS total
             FROM equipment_loans l JOIN equipment e ON e.id = l.equipment_id
             WHERE DATE(l.borrow_date) BETWEEN ? AND ?
             GROUP BY l.equipment_id, e.name ORDER BY total DESC',
            [$from, $to]
        ));

        // Certificates issued (original lines 64-67)
        $certCount = (int) DB::scalar(
            'SELECT COUNT(*) FROM medical_certificates WHERE DATE(issued_at) BETWEEN ? AND ?',
            [$from, $to]
        );

        return [
            'consCount' => $consCount,
            'requestCount' => $requestCount,
            'medicineRequests' => $medicineRequests,
            'medicineChart' => $medicineChart,
            'loanCount' => $loanCount,
            'equipmentLoans' => $equipmentLoans,
            'equipmentChart' => $equipmentChart,
            'certCount' => $certCount,
        ];
    }

    /**
     * Fetch rows as associative arrays (the reference's fetchAll() default
     * carries string keys; array_column() in the chart line reads those).
     *
     * @param  array<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $rows): array
    {
        return array_map(static fn ($row): array => (array) $row, $rows);
    }
}

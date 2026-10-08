<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Queries\AdminReportQueries;
use App\Support\ShellContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Admin - Reports overview (admin/reports.php).
 *
 * GET only (the reference has no POST branch - the period form is GET). The
 * range comes from the ported report_range() helper (whitelist -> monthly,
 * truthy from+to pair -> strtotime()/date() with no validation, otherwise
 * period-derived). Seven aggregates feed the page: consultation count,
 * requested medicine units, medicine request rows + chart series, borrowed
 * equipment sum, equipment loan rows + chart series, certificate count -
 * all scoped with DATE(col) BETWEEN ? AND ?. Rows are cast to arrays so the
 * view's array_column()/array_map('intval') chart building matches the
 * reference's PDO FETCH_BOTH output exactly.
 *
 * The reference's $lowStock query (CURDATE() low-stock scan) is computed
 * but never rendered - dead code dropped, same precedent as Phase 22's
 * $actions DISTINCT query and Phase 25's $categoryOptions.
 *
 * The chart <script> builds JSON with plain json_encode() and RAW echoes
 * in the reference (htmlspecialchars would corrupt the JS); the view keeps
 * them raw via {!! !!} - output is byte-identical, including the lack of
 * JSON_HEX_* escaping (admin-controlled labels only, reference behavior).
 *
 * route: admin.reports, role:admin, GET.
 *
 * Phase 4 (Batch 1): the seven range aggregates and the rows() mapper
 * moved into App\Queries\AdminReportQueries, injected below - this method
 * parses report_range() and renders the view.
 */
class AdminReportsController extends Controller
{
    public function __construct(protected AdminReportQueries $adminReportQueries) {}

    public function index(Request $request): View
    {
        [$period, $from, $to] = report_range(
            (string) $request->query('period', 'monthly'),
            $request->query('from'),
            $request->query('to')
        );

        // The seven range aggregates (and the rows() array casting) moved
        // into App\Queries\AdminReportQueries (Phase 4, Batch 1) in the
        // original query order; this method keeps request parameters and
        // rendering.
        $data = $this->adminReportQueries->overview($from, $to);

        return view('admin.reports', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'admin/reports'),
            'title' => 'Reports',
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'consCount' => $data['consCount'],
            'requestCount' => $data['requestCount'],
            'loanCount' => $data['loanCount'],
            'certCount' => $data['certCount'],
            'medicineRequests' => $data['medicineRequests'],
            'medicineChart' => $data['medicineChart'],
            'equipmentLoans' => $data['equipmentLoans'],
            'equipmentChart' => $data['equipmentChart'],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Queries\StaffReportQueries;
use App\Support\ShellContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Staff - Reports (staff/reports.php).
 *
 * GET only (the reference has no POST branch - the period form submits GET).
 * Same report_range() + DATE() BETWEEN aggregate family as Phase 27's
 * AdminReportsController, minus the certificate count and pie-chart series,
 * plus the staff dashboard's two chart queries (frequently requested /
 * borrowed items - these intentionally IGNORE the period filter, original
 * lines 46-49) and the 7/14/30-day ClinicTrend ranges the IIFE consumes.
 *
 * GROUP BY additionally lists m.name / e.name (functionally dependent on
 * the PKs through the JOINs, so groups/rows are identical) - the same
 * ONLY_FULL_GROUP_BY accommodation as Phase 27 (Laravel opens MySQL in
 * strict mode; the reference PDO runs under the server default).
 *
 * Dead code: the $topPatients query (original lines 37-44) never reaches the
 * view - this page has no patient table - dropped, same precedent as Phase
 * 27's $lowStock / Phase 22's DISTINCT actions. $days stays the literal 7
 * the reference feeds renderTrend() with.
 *
 * route: staff.reports, role:staff, GET (POST -> 405 ACCEPTED PARITY
 * DIFFERENCE - Phase 22/27 precedent, no UI path POSTs).
 *
 * Phase 4 (Batch 1): the range aggregates, chart queries, ClinicTrend
 * ranges and rows() mapper moved into App\Queries\StaffReportQueries,
 * injected below - this method parses report_range() and renders the view.
 */
class StaffReportsController extends Controller
{
    public function __construct(protected StaffReportQueries $staffReportQueries) {}

    public function index(Request $request): View
    {
        [$period, $from, $to] = report_range(
            (string) $request->query('period', 'monthly'),
            $request->query('from'),
            $request->query('to')
        );

        // The range aggregates, chart queries and trend ranges (plus the
        // rows() array casting) moved into App\Queries\StaffReportQueries
        // (Phase 4, Batch 1) in the original query order; this method keeps
        // request parameters and rendering.
        $data = $this->staffReportQueries->overview($from, $to);

        return view('staff.reports', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'staff/reports'),
            'title' => 'Reports',
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'consCount' => $data['consCount'],
            'requestCount' => $data['requestCount'],
            'loanCount' => $data['loanCount'],
            'medicineRequests' => $data['medicineRequests'],
            'equipmentLoans' => $data['equipmentLoans'],
            'requestedItems' => $data['requestedItems'],
            'borrowedItems' => $data['borrowedItems'],
            'trendRanges' => $data['trendRanges'],
            'periodTotal' => $data['periodTotal'],
            'periodChange' => $data['periodChange'],
            'days' => $data['days'],
        ]);
    }
}

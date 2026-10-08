<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Queries\StockLogQueries;
use App\Support\ShellContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Staff deduction logs - port of staff/deduction_logs.php (read-only
 * reference).
 *
 * require_login(['staff']) becomes ['auth', 'role:staff'] route middleware.
 * The reference checks no request method and performs no writes, so this
 * route is a plain GET (same convention as staff/patients, Phase 6). The
 * search builder (four LIKE params over medicine/patient/batch), the
 * ['dispensed','rejected'] status whitelist (any other value - including
 * the missing one - adds NO filter), the LIMIT 200 list, and the two global
 * OUT-movement counters all run exactly like the reference. This page never
 * calls log_action (no audit rows). set_flash equivalents: none exist here.
 *
 * Post-migration addition (feature 1): each listed row is passed through
 * withStockContext(), which reconstructs the before/after figures the
 * feature requires - previous and resulting batch quantity, the pieces left
 * in the open package (12 for tablets, 50 for boxes), and the medicine's
 * total stock after the deduction - from the stock_movements history alone.
 * Nothing here writes to the database.
 *
 * Phase 4 (Batch 1): the list query, the withStockContext() figure
 * reconstruction and both OUT-movement counters moved into
 * App\Queries\StockLogQueries, injected below - this method reads ?q= /
 * ?status= and renders the view.
 */
class DeductionLogsController extends Controller
{
    public function __construct(protected StockLogQueries $stockLogQueries) {}

    public function index(Request $request): View
    {
        // The ?q= / ?status= reads stay here (HTTP); the list query, the
        // withStockContext() figure reconstruction and both OUT-movement
        // counters moved into App\Queries\StockLogQueries (Phase 4, Batch 1)
        // in the original order.
        $search = trim($request->query('q') ?? '');
        $statusFilter = $request->query('status', '');

        $data = $this->stockLogQueries->overview($search, $statusFilter);

        return view('staff.deduction_logs', [
            'title' => 'Deduction Logs',
            'shell' => ShellContext::fromUser($request->user(), activePath: 'staff/deduction_logs'),
            'search' => $search,
            'statusFilter' => $statusFilter,
            'deductions' => $data['deductions'],
            'totalDeductions' => $data['totalDeductions'],
            'todayDeductions' => $data['todayDeductions'],
        ]);
    }
}

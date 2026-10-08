<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\EquipmentLoanService;
use App\Services\StaffHistory;
use App\Support\ShellContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Staff equipment loans - port of staff/equipment_loans.php (read-only
 * reference).
 *
 * require_login(['staff']) becomes ['auth', 'role:staff'] route middleware;
 * the custom CSRF failure branch (flash + redirect) is Laravel's 419
 * (approved Phase 6 difference), which also covers both POST actions the
 * reference guards with its token check.
 *
 * Order matches the reference exactly:
 *
 *  - create_overdue_loan_notifications() runs first (idempotent, only
 *    loans with a past due_date can write - see OverdueLoanNotifications);
 *  - the issue POST validates borrower/equipment/quantity !== 1 (the
 *    single-item rule), re-checks stock, then inside a transaction takes
 *    the conditional UPDATE ... available_qty >= ? - a rowCount miss rolls
 *    back with "Not enough available units." - and inserts the loan with
 *    a 24-hour timeout - see create_overdue_loan_notifications();
 *  - all borrow, return, overdue logic mirrors the reference;
 *  - Post-migration addition: history pagination via StaffHistory.
 *
 * Phase 4 (Batch 2): the issue pipeline (validations, availability check,
 * create transaction) and the overdue-notification sweep (including the
 * controller's former createOverdueLoanNotifications helper) moved into
 * App\Services\EquipmentLoanService, injected below - this method keeps
 * form data, pagination and rendering.
 */
class EquipmentLoansController extends Controller
{
    public function __construct(protected EquipmentLoanService $equipmentLoanService) {}

    public function index(Request $request): View|RedirectResponse
    {
        $errors = [];
        $statusFilter = $request->query('status') ?? 'borrowed';

        // ---- Issue form (original lines 31-83) -----------------------------
        $patients = DB::select(
            "SELECT u.id, CONCAT(p.first_name,' ',p.last_name) AS full_name,
                    u.username, sd.student_no, ed.employee_no
             FROM users u
             LEFT JOIN profiles p ON p.user_id=u.id
             LEFT JOIN student_details sd ON sd.user_id=u.id
             LEFT JOIN employee_details ed ON ed.user_id=u.id
             WHERE u.role='user' ORDER BY p.first_name",
        );

        $equipment = DB::select('SELECT * FROM equipment WHERE is_active=1 ORDER BY name');
        $defaultLoanLimit = get_setting_int('default_equipment_loan_limit', 5);

        // ---- Issue form POST (original lines 85-97): field reads stay
        // here; the validations, availability re-check and the create
        // transaction moved into App\Services\EquipmentLoanService
        // (Phase 4, Batch 2). ----
        if ($request->isMethod('post') && $request->input('do') === 'issue') {
            $borrowerId = (int) ($request->post('user_id') ?? 0);
            $equipmentId = (int) ($request->post('equipment_id') ?? 0);
            $quantity = (int) ($request->post('quantity') ?? 1);

            $result = $this->equipmentLoanService->issue($borrowerId, $equipmentId, $quantity);

            if ($result['success']) {
                return redirect()
                    ->route('staff.equipment_loans')
                    ->with('success', 'Equipment loan issued successfully.');
            }

            $errors = $result['errors'];
        }

        // ---- Status filter toolbar (original lines 99-120) ------------------
        if ($request->isMethod('get') && $request->input('do') !== 'issue') {
            if ($statusFilter === 'overdue') {
                $where = "WHERE l.status = 'borrowed' AND l.borrowed_at <= SUBDATE(NOW(), 1)";
            } elseif (in_array($statusFilter, ['borrowed', 'returned'], true)) {
                $where = 'WHERE l.status = ?';
            } else {
                $where = '1=1';
            }
        } else {
            $where = '1=1';
        }

        // ---- History using StaffHistory (replaces original manual SQL) ------------
        $search = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $filters = [
            'q' => $search,
            'status' => $statusFilter,
        ];

        $result = StaffHistory::rows('equipment-loans', $filters, $perPage, $offset);
        $loans = $result['rows'];
        $totalLoans = $result['total'];
        $historyError = $result['error'];

        $totalPages = (int) ceil($totalLoans / $perPage);
        $pagination = [
            'current' => $page,
            'total' => max(1, $totalPages),
            'perPage' => $perPage,
            'totalItems' => $totalLoans,
            'baseUrl' => url('staff/equipment_loans'),
            'query' => array_filter(['q' => $search, 'status' => $statusFilter]),
        ];

        // Create overdue loan notifications if any overdue loans exist
        // (count + sweep moved into App\Services\EquipmentLoanService,
        // Phase 4, Batch 2).
        $overdueCount = $this->equipmentLoanService->overdueAlertCount($statusFilter);

        return view('staff.equipment_loans', [
            'title' => 'Borrow',
            'shell' => ShellContext::fromUser($request->user(), activePath: 'staff/equipment_loans'),
            'errors' => $errors,
            'patients' => $patients,
            'equipment' => $equipment,
            'loans' => $loans,
            'statusFilter' => $statusFilter,
            'pagination' => $pagination,
            'overdueCount' => $overdueCount,
            'historyError' => $historyError,
        ]);
    }
}

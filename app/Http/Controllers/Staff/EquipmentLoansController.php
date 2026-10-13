<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\EquipmentLoanService;
use App\Services\OverdueLoanNotifications;
use App\Services\StaffHistory;
use App\Support\ShellContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Staff equipment loans page - port of staff/equipment_loans.php; request
 * parsing and rendering here, mutations in EquipmentLoanService.
 */
class EquipmentLoansController extends Controller
{
    // #region Wiring

    // Loan mutations injected; this class only parses requests and renders
    public function __construct(protected EquipmentLoanService $equipmentLoanService) {}

    // #endregion

    // #region Equipment loans page

    // Serve the loans page: overdue sweep first, then issue/return POSTs, then the filtered 10-row preview
    // <- GET ?status/?q, POST do=issue|do=return | -> view or redirect+flash | => overdue_loan notifications
    public function index(Request $request): View|RedirectResponse
    {
        OverdueLoanNotifications::create();

        $errors = [];
        // Default 'all' matches the view's lowercase compare (its "All"
        // option), so the unfiltered page lists every status.
        $statusFilter = $request->query('status') ?? 'all';

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

        if ($request->isMethod('post') && $request->input('do') === 'issue') {
            $borrowerId = (int) ($request->post('user_id') ?? 0);
            $equipmentId = (int) ($request->post('equipment_id') ?? 0);
            $quantity = (int) ($request->post('quantity') ?? 1);
            $notes = trim((string) ($request->post('notes') ?? ''));

            $result = $this->equipmentLoanService->issue($borrowerId, $equipmentId, $quantity, $notes, (int) $request->user()->id);

            if ($result['success']) {
                return redirect()
                    ->route('staff.equipment_loans')
                    ->with('success', 'Equipment issued.');
            }

            $errors = $result['errors'];
        }

        if ($request->isMethod('post') && $request->input('do') === 'return') {
            $returnResult = $this->equipmentLoanService->returnLoan(
                (int) $request->post('loan_id', 0),
                (int) $request->user()->id,
            );

            return redirect()
                ->route('staff.equipment_loans')
                ->with($returnResult['status'], $returnResult['message']);
        }

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

        $search = trim((string) $request->query('q', ''));

        $filters = [
            'q' => $search,
            'status' => $statusFilter,
        ];

        // Module histories feature: 10 newest matching loans only - the
        // complete, paginated loan history lives behind the toolbar's
        // View All action (history() below). ?page= is no longer read
        // here.
        $result = StaffHistory::rows('equipment-loans', $filters, 10, 0);
        $loans = $result['rows'];
        $historyError = $result['error'];

        $overdueCount = $this->equipmentLoanService->overdueAlertCount($statusFilter);

        return view('staff.equipment_loans', [
            'title' => 'Equipment Loans',
            'shell' => ShellContext::fromUser($request->user(), activePath: 'staff/equipment_loans'),
            'errors' => $errors,
            'patients' => $patients,
            'equipment' => $equipment,
            'loans' => $loans,
            'statusFilter' => $statusFilter,
            'overdueCount' => $overdueCount,
            'historyError' => $historyError,
        ]);
    }

    // #endregion
}

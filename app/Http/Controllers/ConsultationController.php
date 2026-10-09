<?php

namespace App\Http\Controllers;

use App\Services\ConsultationService;
use App\Services\StaffHistory;
use App\Support\ShellContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Phase 11 - Consultations.
 *
 * Ports staff/consultations.php (record + history) and
 * user/my_consultations.php (own history). The staff page keeps the
 * original's single-entry order: POST save (lines 12-43) first, then the
 * ?edit= fetch (45-51), then the ?action=delete handler (53-58), and
 * finally the list queries (61-100) - a failed POST falls through all of
 * it and re-renders, exactly like the PHP original.
 *
 * Legacy behaviours preserved:
 *  - the numeric fields read $_POST directly: a blank field stores NULL,
 *    while a key omitted entirely cast null to 0/0.0 (crafted POSTs);
 *  - ?filter is validated for the query but the <select> reads the RAW
 *    value, so an invalid filter selects no option while listing all rows;
 *  - "This Week"/"This Month" are rolling 7/30-day windows, not calendar
 *    periods (labels only);
 *  - ?action=delete is a plain GET (the original has no CSRF on it; the
 *    confirm dialog is client-side only) and any staff member may delete
 *    any consultation - no ownership check exists;
 *  - the toolbar's Search button is a client-side row filter; the server
 *    ?q= parameter is a legacy hook nothing on the page submits, but it is
 *    still honoured;
 *  - fire_event('consultation.created') has no listener (NotificationRouter
 *    registers only student_registered/employee_registered/user_login), so
 *    no notification is written on save.
 *
 * Approved differences: CSRF mismatch raises Laravel's 419 instead of the
 * original's silent flash+redirect (Phase 6 convention), and success flashes
 * ("Consultation saved." / "Consultation deleted.") surface through the
 * shared flash partial although the original never rendered set_flash().
 *
 * Phase 4 (Batch 2): the save pipeline (two validations + insert + audit +
 * Notify) and the delete + audit moved into App\Services\ConsultationService,
 * injected below - this method keeps the $numeric() request reader, the
 * queries, pagination and rendering.
 */
class ConsultationController extends Controller
{
    public function __construct(protected ConsultationService $consultationService) {}

    /**
     * GET|POST /staff/consultations - staff/consultations.php.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $errors = [];

        // ---- Save consultation (original lines 12-43): field reads stay
        // here; the validations, insert, audit and Notify moved into
        // App\Services\ConsultationService (Phase 4, Batch 2). ----
        if ($request->isMethod('post')) {
            // Original lines 20-24 read $_POST[...] directly: '' => null,
            // but a missing key evaluated null !== '' and cast to 0/0.0.
            // ConvertEmptyStringsToNull turns '' into null, so key presence
            // is what distinguishes the two cases here.
            $numeric = function (string $key, bool $int) use ($request) {
                if (! $request->request->has($key)) {
                    return $int ? 0 : 0.0;
                }
                $raw = $request->request->get($key);

                return $raw === null ? null : ($int ? (int) $raw : (float) $raw);
            };

            $pid = (int) $request->input('patient_id', 0);
            $compl = trim((string) $request->input('complaint', ''));
            $bp = trim((string) $request->input('blood_pressure', ''));
            $temp = $numeric('temperature', false);
            $pulse = $numeric('pulse', true);
            $resp = $numeric('respiration', true);
            $wt = $numeric('weight_kg', false);
            $ht = $numeric('height_cm', false);
            $diag = trim((string) $request->input('diagnosis', ''));
            $treat = trim((string) $request->input('treatment', ''));
            $notes = trim((string) $request->input('notes', ''));

            $result = $this->consultationService->save(
                $pid,
                $compl,
                $bp,
                $temp,
                $pulse,
                $resp,
                $wt,
                $ht,
                $diag,
                $treat,
                $notes,
                (int) $request->user()->id,
            );

            if ($result['success']) {
                return redirect()
                    ->route('staff.consultations')
                    ->with('success', 'Consultation saved.');
            }

            $errors = $result['errors'];
        }

        // ---- Edit fetch (original lines 45-51) ---------------------------
        // A missing row gives null (the original's false); both are falsy
        // everywhere the template uses them.
        $editId = (int) $request->query('edit', 0);
        $edit = $editId > 0
            ? DB::table('consultations')->where('id', $editId)->limit(1)->first()
            : null;

        // ---- Delete (original lines 53-58) - a plain GET, any staff ------
        // (delete + audit moved into App\Services\ConsultationService.)
        if ($request->query('action') === 'delete' && ($cid = (int) $request->query('id', 0)) > 0) {
            $this->consultationService->delete($cid, (int) $request->user()->id);

            return redirect()
                ->route('staff.consultations')
                ->with('success', 'Consultation deleted.');
        }

        // ---- Patients for the dropdown (original lines 61-65) ------------
        // Feature: Include student_no/employee_no for the patient combobox
        $patients = DB::select(
            "SELECT u.id, u.account_type, sd.student_no, ed.employee_no,
                    CONCAT(p.first_name,' ',p.last_name) AS full_name
             FROM users u
             LEFT JOIN profiles p ON p.user_id = u.id
             LEFT JOIN student_details sd ON sd.user_id = u.id
             LEFT JOIN employee_details ed ON ed.user_id = u.id
             WHERE u.role='user' ORDER BY p.first_name, p.last_name",
        );

        $preselect = (int) $request->query('patient_id', 0);
        $patientId = $edit->patient_id ?? $preselect;

        // ---- History using StaffHistory (replaces original manual SQL) ------------
        $search = trim((string) $request->query('q', ''));
        $filter = (string) $request->query('filter', 'all');
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $filters = [
            'q' => $search,
        ];

        $result = StaffHistory::rows('consultations', $filters, $perPage, $offset);
        $list = $result['rows'];
        $totalConsultations = $result['total'];
        $consultationError = $result['error'];

        $totalPages = (int) ceil($totalConsultations / $perPage);
        $pagination = [
            'current' => $page,
            'total' => max(1, $totalPages),
            'perPage' => $perPage,
            'totalItems' => $totalConsultations,
            'baseUrl' => url('staff/consultations'),
            'query' => array_filter(['q' => $search, 'filter' => $filter]),
        ];

        return view('staff.consultations', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'staff/consultations'),
            'title' => 'Medical Consultations',
            'errors' => $errors,
            'edit' => $edit,
            'patients' => $patients,
            'patientId' => $patientId,
            'list' => $list,
            'pagination' => $pagination,
            'filterRaw' => $filter,
            'consultationError' => $consultationError,
        ]);
    }

    /**
     * GET /user/my_consultations - user/my_consultations.php.
     */
    public function myConsultations(Request $request): View
    {
        $uid = (int) $request->user()->id;

        $list = DB::select(
            "SELECT c.*, CONCAT(p.first_name,' ',p.last_name) AS staff_name
             FROM consultations c LEFT JOIN profiles p ON p.user_id = c.staff_id
             WHERE c.patient_id = ? ORDER BY c.consultation_date DESC",
            [$uid],
        );

        return view('user.my_consultations', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'user/my_consultations'),
            'title' => 'My Consultations',
            'list' => $list,
        ]);
    }
}

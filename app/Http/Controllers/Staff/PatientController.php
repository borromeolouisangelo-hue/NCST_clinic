<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\PatientRegistrationService;
use App\Services\StaffHistory;
use App\Support\ShellContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;

/**
 * Phase 6 - Patients.
 *
 * Ports staff/patients.php (list + profile view in one page, split by ?id=)
 * and staff/patients register page staff/patient_register.php (form + create).
 *
 * Both pages call require_login(['staff']) in the original; here that is the
 * ['auth', 'role:staff'] middleware pair (guest -> login, wrong role -> own
 * dashboard), the same two redirects require_login() performs.
 *
 * Every query keeps the original SQL semantics: same joins, same WHERE/ORDER,
 * same LIMITs, every user-controlled value bound as a parameter.
 */
class PatientController extends Controller
{
    public function __construct(protected PatientRegistrationService $patientRegistrationService) {}

    /**
     * GET /staff/patients - the patient list (search / type filter), or the
     * single-patient profile when ?id= is present.
     */
    public function index(Request $request): View
    {
        $viewId = (int) $request->query('id', 0);

        // ---- Single patient detail (original lines 12-48) --------------------
        // The original also loaded the 10 most recent consultations and
        // dispenses for the profile's activity cards; those sections were
        // removed from this overview (the records remain on /staff/consultations
        // and /staff/dispense), so their queries went with them.
        $patient = null;
        $medRec = null;
        $allergies = collect();

        if ($viewId > 0) {
            $patient = DB::table('users as u')
                ->leftJoin('profiles as p', 'p.user_id', '=', 'u.id')
                ->leftJoin('student_details as sd', 'sd.user_id', '=', 'u.id')
                ->leftJoin('employee_details as ed', 'ed.user_id', '=', 'u.id')
                ->select(
                    'u.*', 'p.*',
                    'sd.student_no', 'sd.course', 'sd.year_level', 'sd.section',
                    'ed.employee_no', 'ed.department', 'ed.position'
                )
                ->where('u.id', $viewId)
                // Only patient accounts profile-render: a staff/admin id (or a
                // nonexistent one) resolves to null and the page falls back to
                // the list, so ?id= can never surface a non-patient account.
                ->where('u.role', 'user')
                ->limit(1)
                ->first();

            if ($patient) {
                $medRec = DB::table('medical_records')
                    ->where('user_id', $viewId)
                    ->orderByDesc('submitted_at')
                    ->limit(1)
                    ->first();

                $allergies = DB::table('user_allergies')
                    ->where('user_id', $viewId)
                    ->get();
            }
        }

        // ---- Patient list using StaffHistory (replaces original list with pagination) --
        $search = trim((string) $request->query('q', ''));
        $typeParam = $request->query('type', '');
        $typeFilter = in_array($typeParam, ['all', 'student', 'employee'], true)
            ? $typeParam
            : 'all';
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 20;

        $filters = [
            'q' => $search,
            'type' => $typeFilter,
        ];
        $offset = ($page - 1) * $perPage;

        $result = StaffHistory::rows('patients', ['q' => $search, 'type' => $typeFilter], $perPage, ($page - 1) * $perPage);
        $patients = $result['rows'];
        $totalPatients = $result['total'];
        $patientError = $result['error'];

        // Pagination
        $totalPages = (int) ceil($totalPatients / $perPage);
        $pagination = [
            'current' => $page,
            'total' => $totalPages,
            'perPage' => $perPage,
            'totalItems' => $totalPatients,
            'baseUrl' => url('staff/patients'),
            'query' => array_filter(['q' => $search, 'type' => $typeFilter]),
        ];

        // ---- Search datalist (original lines 86-95) --------------------------
        $patientSuggestions = DB::table('users as u')
            ->leftJoin('profiles as p', 'p.user_id', '=', 'u.id')
            ->leftJoin('student_details as sd', 'sd.user_id', '=', 'u.id')
            ->leftJoin('employee_details as ed', 'ed.user_id', '=', 'u.id')
            ->select(
                DB::raw("CONCAT(p.first_name, ' ', p.last_name) AS full_name"),
                'u.username', 'u.account_type', 'sd.student_no', 'ed.employee_no'
            )
            ->where('u.role', 'user')
            ->where('u.status', 'active')
            ->orderBy('p.first_name')
            ->orderBy('p.last_name')
            ->get();

        return view('staff.patients', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'staff/patients'),
            'title' => 'Patients',
            'patient' => $patient,
            'medRec' => $medRec,
            'allergies' => $allergies,
            'search' => $search,
            'typeFilter' => $typeFilter,
            'patients' => $patients,
            'patientSuggestions' => $patientSuggestions,
            'pagination' => $pagination,
            'patientError' => $patientError,
        ]);
    }

    /**
     * GET /staff/patient_register - the register form.
     *
     * The original reads `$_GET['type']` on line 11, but that value never
     * reaches the markup: line 80 requires the shared layout, which pulls in
     * topbar.php, whose line 19 reassigns the same global to
     * `$_SESSION['account_type']` - the logged-in user's own type - before
     * anything renders. The served page therefore always shows that user's
     * card (staff -> "Register Employee") and `?type=` is dead. This port
     * replicates the served behaviour, not the unused branch.
     */
    public function create(Request $request): View
    {
        return $this->form($request, new MessageBag);
    }

    /**
     * POST /staff/patient_register - validation, uniqueness check, transaction
     * (users + profiles + student_details|employee_details), audit, redirect.
     *
     * Validation messages and their order are the original's exact strings;
     * like the original, a failed POST re-renders the form in the same request
     * (no redirect, inputs are not repopulated).
     *
     * Phase 4 (Batch 2): the keyed validations, the uniqueness check, the
     * create transaction (users + profiles + student_details|employee_details)
     * and the audit row moved into App\Services\PatientRegistrationService,
     * injected above - this method reads the POST fields and renders.
     */
    public function store(Request $request): View|RedirectResponse
    {
        $username = trim((string) $request->input('username', ''));
        $email = trim((string) $request->input('email', ''));
        $accountType = (string) $request->input('account_type', 'student');
        $password = (string) $request->input('password', '');
        $first = trim((string) $request->input('first_name', ''));
        $last = trim((string) $request->input('last_name', ''));
        $type = $accountType === 'employee' ? 'employee' : 'student';
        $address = format_address([
            $request->input('lot_block', ''),
            $request->input('street', ''),
            $request->input('barangay', ''),
            $request->input('postal_code', ''),
            $request->input('city_municipality', ''),
            $request->input('province', ''),
        ]);
        $province = trim((string) $request->input('province', ''));
        $cityMunicipality = trim((string) $request->input('city_municipality', ''));
        $input = [
            'student_no' => trim((string) $request->input('student_no', '')),
            'employee_no' => trim((string) $request->input('employee_no', '')),
            'middle_name' => trim((string) $request->input('middle_name', '')),
            'gender' => $request->input('gender', ''),
            'birthdate' => $request->input('birthdate'),
            'contact_no' => trim((string) $request->input('contact_no', '')),
            'emergency_name' => trim((string) $request->input('emergency_name', '')),
            'emergency_contact' => trim((string) $request->input('emergency_contact', '')),
            'course' => trim((string) $request->input('course', '')),
            'year_level' => trim((string) $request->input('year_level', '')),
            'section' => trim((string) $request->input('section', '')),
            'department' => trim((string) $request->input('department', '')),
            'position' => trim((string) $request->input('position', '')),
        ];

        // The keyed validations, uniqueness check, create transaction and
        // audit row moved into App\Services\PatientRegistrationService
        // (Phase 4, Batch 2); success PRGs here, failure re-renders below.
        $result = $this->patientRegistrationService->register(
            $username,
            $email,
            $password,
            $first,
            $last,
            $type,
            $address,
            $province,
            $cityMunicipality,
            $input,
            (int) $request->user()->id,
        );

        if ($result['success']) {
            return redirect()
                ->route('staff.patients')
                ->with('success', "Patient account $username created successfully.");
        }

        // Same-request re-render (original: no redirect, inputs stay empty).
        // The service's validations already used the POSTed account_type
        // (original line 24 computes $type before the layout include), but
        // the rendered card does not: topbar.php:19 overwrites $accountType
        // on line 80, after the POST block - so a failed student submission
        // re-renders with the logged-in user's own card, exactly like the
        // original.
        return $this->form($request, new MessageBag($result['errors']));
    }

    /**
     * Render the register form (used by GET and by the failed-POST re-render).
     *
     * `errors` is passed explicitly so the view prints the original
     * `<div class="alert alert-danger py-2">` list instead of the shared
     * dismissible flash slot.
     *
     * `accountType` mirrors topbar.php:19 (session account_type of the
     * logged-in user, 'user' when unset) - never `?type=` or the POSTed value.
     */
    private function form(Request $request, MessageBag $errors): View
    {
        return view('staff.patient_register', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'staff/patient_register'),
            'title' => 'Register Patient',
            'accountType' => $request->user()->account_type ?? 'user',
            'errors' => $errors,
        ]);
    }
}

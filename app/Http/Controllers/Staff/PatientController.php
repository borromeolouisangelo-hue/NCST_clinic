<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\PatientActivityService;
use App\Services\PatientRegistrationService;
use App\Services\ProfilePhoto;
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
 *
 * Beyond the ported pages, activities() renders the Patient Activity
 * History page (GET /staff/patient_activities): the combined activity feed
 * of one patient through App\Services\PatientActivityService, reusing the
 * exact resolvePatient() lookup the overview's ?id= view performs, and
 * activityDetail() renders one read-only record of that feed
 * (GET /staff/patient_activity_detail, ?type= + ?id=).
 */
class PatientController extends Controller
{
    public function __construct(
        protected PatientRegistrationService $patientRegistrationService,
        protected PatientActivityService $patientActivityService,
    ) {}

    /**
     * GET /staff/patients - the patient list (search / type filter), or the
     * single-patient profile when ?id= is present.
     */
    public function index(Request $request): View
    {
        $viewId = (int) $request->query('id', 0);

        // ---- Single patient detail (original lines 12-48) --------------------
        // The original's per-type activity cards (recent consultations +
        // recent dispenses) are replaced below by one combined Recent
        // Activity card: the same 10-row preview scope, now merged across
        // every activity family through PatientActivityService.
        $patient = null;
        $medRec = null;
        $allergies = collect();

        if ($viewId > 0) {
            $patient = $this->resolvePatient($viewId);

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

        // ---- Recent Activity preview (the 10 newest combined activities;
        // the full, searchable history lives behind the "View All
        // Activities" button on the dedicated page). ----
        $recentActivities = $patient
            ? $this->patientActivityService->recent((int) $patient->id, (string) $patient->account_type)
            : [];

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
            // The patient's stored picture - profiles.photo arrives with the
            // resolvePatient() join (p.user_id = u.id), so it is always this
            // patient's own file; '' makes the view fall back to the default
            // avatar placeholder.
            'patientPhotoUrl' => ProfilePhoto::url($patient->photo ?? null),
            'medRec' => $medRec,
            'allergies' => $allergies,
            'recentActivities' => $recentActivities,
            'search' => $search,
            'typeFilter' => $typeFilter,
            'patients' => $patients,
            'patientSuggestions' => $patientSuggestions,
            'pagination' => $pagination,
            'patientError' => $patientError,
        ]);
    }

    /**
     * GET /staff/patient_activities - the Patient Activity History page:
     * the full combined activity feed (consultations, dispensing, equipment
     * loans, certificates and - employees only - sick leaves) for ONE
     * patient, newest first, with search, activity-type filter and
     * server-side pagination.
     *
     * Read-only: the page renders existing records through
     * PatientActivityService and never writes. Patient access reuses the
     * overview's exact lookup, so a missing/invalid/non-patient id can
     * never reach another account's records - it redirects to the list.
     */
    public function activities(Request $request): View|RedirectResponse
    {
        $patient = $this->resolvePatient((int) $request->query('id', 0));

        if (! $patient) {
            return redirect()->route('staff.patients');
        }

        // Same whitelist pattern as the patient list: the search text is
        // trimmed, the type is validated against THIS patient's options
        // (Sick Leave is never an option for students), and the page is
        // clamped to >= 1. Everything the pagination links rebuild lives in
        // $pagination['query'] below, so patient, search and filter survive
        // page navigation.
        $search = trim((string) $request->query('q', ''));
        $typeParam = (string) $request->query('type', 'all');
        $typeOptions = $this->patientActivityService->filterOptions((string) $patient->account_type);
        $typeFilter = isset($typeOptions[$typeParam]) ? $typeParam : 'all';
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 15;

        $result = $this->patientActivityService->search(
            (int) $patient->id,
            (string) $patient->account_type,
            $search,
            $typeFilter,
            $perPage,
            ($page - 1) * $perPage,
        );

        $totalPages = (int) ceil($result['total'] / $perPage);
        $pagination = [
            'current' => $page,
            'total' => $totalPages,
            'perPage' => $perPage,
            'totalItems' => $result['total'],
            'baseUrl' => url('staff/patient_activities'),
            // Callback keeps a "0" search (dropping only blank values), so a
            // numeric q survives into the page links like id/type do.
            'query' => array_filter([
                'id' => (int) $patient->id,
                'q' => $search,
                'type' => $typeFilter === 'all' ? '' : $typeFilter,
            ], fn ($value) => $value !== '' && $value !== null),
        ];

        return view('staff.patient_activities', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'staff/patients'),
            'title' => 'Patient Activity History',
            'patient' => $patient,
            // Same source as the overview (resolvePatient()'s profiles join),
            // so both pages always show the SAME picture for this patient.
            'patientPhotoUrl' => ProfilePhoto::url($patient->photo ?? null),
            'activities' => $result['rows'],
            'search' => $search,
            'typeFilter' => $typeFilter,
            'typeOptions' => $typeOptions,
            'pagination' => $pagination,
        ]);
    }

    /**
     * GET /staff/patient_activity_detail - the read-only View target
     * behind the overview's Recent Activity list and the activity history
     * page (?type= + ?id=): ONE existing record of ONE activity family.
     *
     * The service only returns a record that exists AND belongs to a
     * patient (role=user; sick leaves additionally require an employee),
     * so a tampered or stale link - like an invalid patient id - redirects
     * to the patient list instead of rendering another account's data.
     * Read-only: nothing here writes and the page offers no edit, delete
     * or print actions (medical certificates keep their own existing
     * ?view= page on the certificates module).
     */
    public function activityDetail(Request $request): View|RedirectResponse
    {
        $activity = $this->patientActivityService->detail(
            (string) $request->query('type', ''),
            (int) $request->query('id', 0),
        );

        $patient = $activity
            ? $this->resolvePatient((int) $activity['patient_id'])
            : null;

        if (! $patient) {
            return redirect()->route('staff.patients');
        }

        return view('staff.patient_activity_detail', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'staff/patients'),
            'title' => $activity['label'].' Details',
            'patient' => $patient,
            'activity' => $activity,
        ]);
    }

    /**
     * The single patient lookup shared by the overview (?id= profile view)
     * and the activity history page: profile + student/employee details in
     * one row, and ONLY role=user accounts resolve - a staff/admin id (or a
     * nonexistent one) yields null so ?id= can never surface a non-patient
     * account.
     *
     * ->id is always the PATIENT'S USER id (u.id re-stated last in the
     * select): p.* repeats u.id, created_at and updated_at, and this driver
     * keeps the last duplicate, which made ->id the PROFILE id and misrouted
     * every ->id-built link (View All, Back, pagination) to whichever other
     * patient happened to own that profile id once profiles.id and users.id
     * had drifted apart (as they always do once any user has no profile).
     */
    private function resolvePatient(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        return DB::table('users as u')
            ->leftJoin('profiles as p', 'p.user_id', '=', 'u.id')
            ->leftJoin('student_details as sd', 'sd.user_id', '=', 'u.id')
            ->leftJoin('employee_details as ed', 'ed.user_id', '=', 'u.id')
            ->select(
                'u.*', 'p.*',
                'sd.student_no', 'sd.course', 'sd.year_level', 'sd.section',
                'ed.employee_no', 'ed.department', 'ed.position',
                // Re-state the user id AFTER p.* so ->id cannot resolve to
                // the profile id (see the docblock above).
                'u.id as id',
            )
            ->where('u.id', $id)
            ->where('u.role', 'user')
            ->limit(1)
            ->first();
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

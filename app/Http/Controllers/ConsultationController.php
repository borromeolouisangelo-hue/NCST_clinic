<?php

namespace App\Http\Controllers;

use App\Services\ConsultationImages;
use App\Services\ConsultationService;
use App\Services\StaffHistory;
use App\Support\ShellContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Consultations - port of staff/consultations.php (record + history) and
 * user/my_consultations.php (own history); save/delete live in
 * ConsultationService, this class parses requests and renders.
 */
class ConsultationController extends Controller
{
    // #region Wiring

    // Consultation mutations injected; this class only parses and renders
    public function __construct(protected ConsultationService $consultationService) {}

    // #endregion

    // #region Staff consultations page

    // Run the consultations page: save POST, ?edit fetch, GET delete, then the filtered 10-row preview
    // <- POST fields, GET ?edit/?action=delete/?filter/?q | -> view or redirect+flash | => audit + patient Notify (ConsultationService)
    public function index(Request $request): View|RedirectResponse
    {
        $errors = [];

        if ($request->isMethod('post')) {
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

            // Optional image attachments below the diagnosis / treatment
            // textareas: images only (jpg/jpeg/png/webp), 5 MB ceiling -
            // validated here on the server before anything is stored.
            $diagImage = $request->file('diagnosis_image');
            $treatImage = $request->file('treatment_image');

            $fileErrors = Validator::make(
                [
                    'diagnosis_image' => $diagImage,
                    'treatment_image' => $treatImage,
                ],
                [
                    'diagnosis_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.ConsultationImages::MAX_KILOBYTES],
                    'treatment_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.ConsultationImages::MAX_KILOBYTES],
                ],
                [
                    'diagnosis_image.file' => 'Attach Diagnosis Image must be a valid image file.',
                    'diagnosis_image.mimes' => 'Diagnosis image must be a JPG, JPEG, PNG or WEBP file.',
                    'diagnosis_image.max' => 'Diagnosis image must not be larger than 5 MB.',
                    'treatment_image.file' => 'Attach Treatment Image must be a valid image file.',
                    'treatment_image.mimes' => 'Treatment image must be a JPG, JPEG, PNG or WEBP file.',
                    'treatment_image.max' => 'Treatment image must not be larger than 5 MB.',
                ],
            )->errors()->all();

            $result = $fileErrors === [] ? $this->consultationService->save(
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
                $diagImage,
                $treatImage,
            ) : null;

            if ($result !== null && $result['success']) {
                return redirect()
                    ->route('staff.consultations')
                    ->with('success', 'Consultation saved.');
            }

            $errors = array_merge($fileErrors, $result['errors'] ?? []);
        }

        $editId = (int) $request->query('edit', 0);
        $edit = $editId > 0
            ? DB::table('consultations')->where('id', $editId)->limit(1)->first()
            : null;

        if ($request->query('action') === 'delete' && ($cid = (int) $request->query('id', 0)) > 0) {
            $this->consultationService->delete($cid, (int) $request->user()->id);

            return redirect()
                ->route('staff.consultations')
                ->with('success', 'Consultation deleted.');
        }

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

        $search = trim((string) $request->query('q', ''));
        $filter = (string) $request->query('filter', 'all');

        $filters = [
            'q' => $search,
            'filter' => $filter,
        ];

        // Module histories feature: the module page is a recent-activity
        // preview - the 10 newest matches, newest first, with the toolbar
        // (search + date filter) still narrowing them. The complete,
        // paginated history lives behind the toolbar's View All action
        // (history() below); ?page= is no longer read here.
        $result = StaffHistory::rows('consultations', $filters, 10, 0);
        $list = $result['rows'];
        $consultationError = $result['error'];

        return view('staff.consultations', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'staff/consultations'),
            'title' => 'Medical Consultations',
            'errors' => $errors,
            'edit' => $edit,
            'patients' => $patients,
            'patientId' => $patientId,
            'list' => $list,
            'filterRaw' => $filter,
            'consultationError' => $consultationError,
        ]);
    }

    // #endregion

    // #region Consultation image attachments

    // Stream ONE stored attachment image of a consultation. The files live
    // on the private local disk and are never exposed through a public URL:
    // staff may view any consultation's images, a patient only their own.
    // <- route {id} (consultation) + {type} (diagnosis|treatment) | -> file response | -> 403/404
    public function attachment(Request $request, int $id, string $type): BinaryFileResponse
    {
        abort_unless(in_array($type, ['diagnosis', 'treatment'], true), 404);

        $consultation = DB::table('consultations')->where('id', $id)->limit(1)->first();
        abort_if($consultation === null, 404);

        $user = $request->user();
        if ($user->role !== 'staff' && (int) $consultation->patient_id !== (int) $user->id) {
            abort(403);
        }

        $filename = $type === 'diagnosis'
            ? ($consultation->diagnosis_image ?? null)
            : ($consultation->treatment_image ?? null);
        $path = ConsultationImages::path($filename);
        abort_if($path === null, 404);

        return response()->file($path);
    }

    // #endregion

    // #region Student consultation history

    // List the student's own consultation history, newest first
    // <- auth user id | -> user.my_consultations view rows
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

    // #endregion
}

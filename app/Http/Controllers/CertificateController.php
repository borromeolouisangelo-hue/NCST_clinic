<?php

namespace App\Http\Controllers;

use App\Services\CertificateService;
use App\Support\ShellContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Phase 12 - Medical certificates.
 *
 * Ports staff/certificates.php (issue + list + print + delete) and
 * user/my_certificates.php (own list + print). The staff page keeps the
 * original's single-entry order: POST issue (lines 12-38) first, then the
 * ?action=delete handler (40-45), then the patients query (47-50), the
 * ?print= fetch (53-69) and the list query (71-83) - a failed POST falls
 * through all of it and re-renders, exactly like the PHP original.
 *
 * Legacy behaviours preserved:
 *  - validation is the original's two rules in the original order
 *    ("Select a patient." then "Purpose is required."); no other field is
 *    validated, so findings/recommendation/rest_days accept anything;
 *  - generate_cert_no() is called without a $type, so every certificate
 *    gets a MED number (functions.php:720 default, never overridden);
 *  - ?action=delete is a plain GET with no ownership check: any staff
 *    member may delete any certificate (the original checks only role);
 *  - the staff ?print= fetch has no ownership check either; the user page
 *    adds `AND c.user_id = ?` exactly like the original;
 *  - ?print= that matches nothing renders the LIST branch (no 404);
 *  - the toolbar's Filter <select> submits ?type= but the SQL never reads
 *    it - the dead filter is kept, including its selected-state logic;
 *  - "Issued Today" compares PHP wall dates (functions.php line 167), so
 *    it runs on the app clock (Europe/Berlin) like the reference;
 *  - fire_event('certificate.issued') has no listener (grep of the
 *    reference finds only the call itself), so no notification is written.
 *
 * Approved differences: CSRF mismatch raises Laravel's 419 instead of the
 * original's flash+redirect (Phase 6 convention), success flashes surface
 * through the shared flash partial, and $cert/$c are stdClass objects
 * (->x) instead of PDO assoc arrays (['x']).
 *
 * Phase 4 (Batch 2): the issue pipeline (two validations + insert + audit
 * + Notify) and the delete + audit moved into App\Services\CertificateService,
 * injected below - this method keeps field reads, the queries and rendering.
 */
class CertificateController extends Controller
{
    public function __construct(protected CertificateService $certificateService) {}

    /**
     * GET|POST /staff/certificates - staff/certificates.php.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $errors = [];

        // ---- Issue certificate (original lines 12-38): field reads stay
        // here; the validations, insert, audit and Notify moved into
        // App\Services\CertificateService (Phase 4, Batch 2). ----
        if ($request->isMethod('post')) {
            // Original lines 17-22 read $_POST directly. ConvertEmptyStrings-
            // ToNull has already turned '' into null, so key presence (has)
            // reproduces `$_POST['cert_type'] ?? 'medical'`: a present-but-
            // empty value must stay '' (it reaches the INSERT verbatim).
            $pid = (int) $request->input('patient_id', 0);
            $type = $request->request->has('cert_type')
                ? trim((string) $request->request->get('cert_type'))
                : 'medical';
            $purpose = trim((string) $request->input('purpose', ''));
            $find = trim((string) $request->input('findings', ''));
            $reco = trim((string) $request->input('recommendation', ''));
            $restRaw = $request->request->get('rest_days');
            $rest = ($restRaw === null || $restRaw === '') ? null : (int) $restRaw;

            $result = $this->certificateService->issue(
                $pid,
                $type,
                $purpose,
                $find,
                $reco,
                $rest,
                (int) $request->user()->id,
            );

            if ($result['success']) {
                return redirect()
                    ->route('staff.certificates', ['print' => $result['cid']])
                    ->with('success', 'Certificate issued.');
            }

            $errors = $result['errors'];
        }

        // ---- Delete (original lines 40-45) - a plain GET, any staff -------
        // (delete + audit moved into App\Services\CertificateService.)
        if ($request->query('action') === 'delete' && ($cid = (int) $request->query('id', 0)) > 0) {
            $this->certificateService->delete($cid, (int) $request->user()->id);

            return redirect()
                ->route('staff.certificates')
                ->with('success', 'Certificate deleted.');
        }

        // ---- Patients for the dropdown (original lines 47-50) ------------
        $patients = DB::select(
            "SELECT u.id, CONCAT(p.first_name,' ',p.last_name) AS full_name
             FROM users u LEFT JOIN profiles p ON p.user_id=u.id WHERE u.role='user' ORDER BY p.first_name",
        );

        // ---- Printable certificate (original lines 53-69) ----------------
        // A missing row gives null (the original's false); both are falsy in
        // the template's `if ($cert)` and fall through to the list branch.
        $printId = (int) $request->query('print', 0);
        $cert = $printId > 0
            ? DB::selectOne(
                "SELECT c.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.birthdate, p.gender, p.address, p.contact_no,
                    sd.student_no, ed.employee_no,
                    CONCAT(ip.first_name, ' ', ip.last_name) AS issuer_name
                 FROM medical_certificates c
                 LEFT JOIN profiles p ON p.user_id = c.user_id
                 LEFT JOIN student_details sd ON sd.user_id = c.user_id
                 LEFT JOIN employee_details ed ON ed.user_id = c.user_id
                 LEFT JOIN profiles ip ON ip.user_id = c.issued_by
                 WHERE c.id=? LIMIT 1",
                [$printId],
            )
            : null;

        // ---- List (original lines 71-83) --------------------------------
        $search = trim((string) $request->query('q', ''));
        $sql = "SELECT c.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, p.gender, p.birthdate
                FROM medical_certificates c
                LEFT JOIN profiles p ON p.user_id = c.user_id";
        $params = [];
        if ($search !== '') {
            $sql .= ' WHERE p.first_name LIKE ? OR p.last_name LIKE ? OR c.purpose LIKE ?';
            array_push($params, "%$search%", "%$search%", "%$search%");
        }
        $sql .= ' ORDER BY c.issued_at DESC LIMIT 200';
        $certs = DB::select($sql, $params);

        return view('staff.certificates', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'staff/certificates'),
            'title' => 'Medical Certificates',
            'errors' => $errors,
            'patients' => $patients,
            'cert' => $cert,
            'certs' => $certs,
            // The Filter <select> reads the RAW ?type= (missing -> 'all'),
            // exactly like the original's `$_GET['type'] ?? 'all'`.
            'typeRaw' => $request->query('type', 'all'),
        ]);
    }

    /**
     * GET /user/my_certificates - user/my_certificates.php.
     */
    public function myCertificates(Request $request): View
    {
        $uid = (int) $request->user()->id;

        // ---- Printable certificate (original lines 11-24) ----------------
        $printId = (int) $request->query('print', 0);
        $cert = $printId > 0
            ? DB::selectOne(
                "SELECT c.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, sd.student_no, ed.employee_no
                 FROM medical_certificates c
                 LEFT JOIN profiles p ON p.user_id = c.user_id
                 LEFT JOIN student_details sd ON sd.user_id = c.user_id
                 LEFT JOIN employee_details ed ON ed.user_id = c.user_id
                 WHERE c.id = ? AND c.user_id = ? LIMIT 1",
                [$printId, $uid],
            )
            : null;

        // ---- Own certificates (original lines 26-28) ---------------------
        $certs = DB::select(
            'SELECT * FROM medical_certificates WHERE user_id = ? ORDER BY issued_at DESC',
            [$uid],
        );

        return view('user.my_certificates', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'user/my_certificates'),
            'title' => 'My Certificates',
            'cert' => $cert,
            'certs' => $certs,
        ]);
    }
}

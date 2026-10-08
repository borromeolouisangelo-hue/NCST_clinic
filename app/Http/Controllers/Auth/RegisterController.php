<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\RegisterService;
use App\Support\ShellContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Self-registration - port of auth/register.php (all 301 lines).
 *
 * The original is one flat file: the is_logged_in() bounce (lines 7-9)
 * guards GET and POST alike, so the bounce lives at the top of this single
 * handler too. The POST block (lines 22-90) runs in this exact order:
 *
 *  1. every $old key trimmed (lines 23-25) - password/confirm kept raw;
 *     $type collapses anything but the literal 'employee' to 'student'
 *     (line 28, account_type itself is only ever trimmed, never validated);
 *  2. the seven field checks (lines 31-39) ALL run and accumulate in order
 *     - no early return, so one POST can show six messages at once;
 *  3. uniqueness (lines 42-48) only when the field checks passed: a single
 *     COUNT over username OR email -> "already taken";
 *  4. users -> profiles -> student_details|employee_details inside one
 *     transaction (lines 50-81), then audit 'register' (line 82), flash
 *     "Account created successfully..." and PRG to the login page (83-84);
 *  5. QueryException -> transaction rolled back, the driver's own message
 *     appended to "Registration failed: " (lines 85-88) and the form
 *     re-renders with $errors - the original catches PDOException, so the
 *     previous exception's message is used for byte-shape parity.
 *
 * An error POST re-renders DIRECTLY (status 200, no PRG) exactly like the
 * original, which prints its markup right after the POST block; only the
 * success path redirects. The original page runs NO CSRF check and prints
 * no token; the web middleware requires one here (approved Phase 6
 * strengthening - same divergence as the login form) and a token-less
 * POST raises 419.
 *
 * route: register, /auth/register (the ORIGINAL auth/register.php path -
 * the /auth/logout precedent - which also lights up the login page's
 * Create an Account button), no role middleware: guests only by bounce.
 *
 * Phase 4 (Batch 2): the nine field checks, the uniqueness COUNT, the
 * create transaction (users -> profiles -> student/employee details) and
 * the audit row moved into App\Services\RegisterService, injected above -
 * this method bounces signed-in users, trims $old and renders/redirects.
 */
class RegisterController extends Controller
{
    public function __construct(protected RegisterService $registerService) {}

    /**
     * The $old map (original lines 12-20): every repopulated field, all
     * empty except account_type defaulting to 'student'. Passwords are
     * deliberately absent - the original never re-renders them.
     */
    private const OLD_DEFAULTS = [
        'username' => '', 'email' => '', 'account_type' => 'student',
        'first_name' => '', 'middle_name' => '', 'last_name' => '',
        'gender' => '', 'birthdate' => '', 'lot_block' => '', 'street' => '',
        'barangay' => '', 'postal_code' => '', 'city_municipality' => '', 'province' => '', 'contact_no' => '',
        'emergency_name' => '', 'emergency_contact' => '',
        'student_no' => '', 'course' => '', 'year_level' => '', 'section' => '',
        'employee_no' => '', 'department' => '', 'position' => '',
    ];

    /**
     * GET|POST /auth/register - the form and its self-post.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $signedIn = $request->user();

        if ($signedIn !== null) {
            return redirect()->route(ShellContext::dashboardRouteName($signedIn->role));
        }

        $errors = [];
        $old = self::OLD_DEFAULTS;

        if ($request->isMethod('post')) {
            foreach (array_keys($old) as $key) {
                $old[$key] = trim((string) $request->input($key, ''));
            }
            $password = (string) $request->input('password', '');
            $confirm = (string) $request->input('password_confirm', '');

            // The nine field checks, the uniqueness COUNT, the create
            // transaction (users -> profiles -> student/employee details)
            // and the audit row live in App\Services\RegisterService
            // (Phase 4, Batch 2); success PRGs to login here, errors
            // re-render the form directly - exactly the original flow.
            $result = $this->registerService->register($old, $password, $confirm);

            if ($result['success']) {
                return redirect()
                    ->route('login')
                    ->with('success', 'Account created successfully. You may now log in.');
            }

            $errors = $result['errors'];
        }

        return view('auth.register', [
            'errors' => $errors,
            'old' => $old,
        ]);
    }
}

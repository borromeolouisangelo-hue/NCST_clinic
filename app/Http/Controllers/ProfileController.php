<?php

namespace App\Http\Controllers;

use App\Services\PasswordChange;
use App\Services\ProfilePhoto;
use App\Services\ProfileService;
use App\Support\ShellContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Feature 5 - user/profile.php is now the ONE Account Settings page every
 * role opens (Profile | Security).
 *
 * The original split the two halves across user/profile.php and the three
 * <role>/settings.php pages, so the shell had to carry two separate sidebar
 * entries ("Profile" and "Account Settings") that both landed on copies of
 * the same account card. They are merged here: this route keeps the
 * identity form it has always had and gains the Security half (account
 * card + password change) plus the profile picture card, so the sidebar
 * collapses to a single "Account Settings" item for all four roles.
 *
 * Bare require_login() in the reference means NO role allow-list: all four
 * roles edit the same profiles row plus their account-type's detail row.
 * The password rule set is {@see PasswordChange} - the exact sequence the
 * settings controllers used to keep privately, now shared by all three so
 * it cannot drift.
 *
 * Phase 4 (Batch 2): the identity update, the photo remove/upload (its
 * Validator included) moved into App\Services\ProfileService, injected
 * below - the password branch stays here as the thin dispatch to
 * PasswordChange (already the shared service), and this method keeps the
 * row fetch, field reads, dispatch, tab logic and rendering.
 */
class ProfileController extends Controller
{
    public function __construct(protected ProfileService $profileService) {}

    /**
     * Render the tabbed Account Settings page; POST runs whichever form
     * the `form_action` hidden field names.
     *
     * The row is fetched BEFORE the POST handling (original lines 21-31), so
     * a failed submit re-renders the stored values under the error alerts.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $uid = (int) $request->user()->id;
        $data = $this->fetchRow($uid);

        $errors = [];
        $settingsErrors = [];
        $photoErrors = [];
        $formAction = (string) $request->input('form_action', '');

        if ($request->isMethod('post') && $formAction === 'profile') {
            // Original line 34 soft-failed an invalid CSRF token into
            // $errors, which the empty-guard below then treated as a write
            // blocker. The port's global VerifyCsrfToken middleware rejects
            // the same token-less POST up front with 419 - the approved
            // Phase 6 strengthening every migrated POST form gets.

            $first = trim((string) $request->input('first_name', ''));
            $mid = trim((string) $request->input('middle_name', ''));
            $last = trim((string) $request->input('last_name', ''));

            $genderInput = (string) $request->input('gender', '');
            $gender = in_array($genderInput, ['male', 'female', 'other'], true) ? $genderInput : null;

            $birthdate = $request->input('birthdate') ?: null;
            $address = trim((string) $request->input('address', ''));
            $contact = trim((string) $request->input('contact_no', ''));
            $emergencyName = trim((string) $request->input('emergency_name', ''));
            $emergencyContact = trim((string) $request->input('emergency_contact', ''));

            $details = [
                'course' => trim((string) $request->input('course', '')),
                'year_level' => trim((string) $request->input('year_level', '')),
                'section' => trim((string) $request->input('section', '')),
                'department' => trim((string) $request->input('department', '')),
                'position' => trim((string) $request->input('position', '')),
            ];

            // The two validations, the profiles UPDATE, the account-type
            // detail UPDATE and the audit row live in
            // App\Services\ProfileService (Phase 4, Batch 2).
            $result = $this->profileService->updateProfile(
                $uid,
                $first,
                $mid,
                $last,
                $gender,
                $birthdate,
                $address,
                $contact,
                $emergencyName,
                $emergencyContact,
                ($data['account_type'] ?? '') === 'student',
                $details,
            );

            if ($result['success']) {
                return redirect()
                    ->route('user.profile')
                    ->with('success', 'Profile updated.');
            }

            $errors = $result['errors'];
        }

        // ------------------------------------------------------------------
        // Feature 5 - profile picture: its own multipart form, so the identity
        // form's markup stays byte-identical (no enctype) for the tests that
        // pin it. Upload and Remove are two named submit buttons on the same
        // form, which the shared submit guard handles per-submitter.
        // ------------------------------------------------------------------
        if ($request->isMethod('post') && $formAction === 'photo') {
            if ((string) $request->input('photo_action', '') === 'remove') {
                $this->profileService->removePhoto($uid, $data['photo'] ?? null);

                return redirect()
                    ->route('user.profile')
                    ->with('success', 'Profile picture removed.');
            }

            // The Validator (image sniff), the file swap, the column update
            // and the audit row live in App\Services\ProfileService
            // (Phase 4, Batch 2); failure returns here for an in-place
            // re-render, exactly like the original else-branch.
            $result = $this->profileService->uploadPhoto($uid, $data['photo'] ?? null, $request->all());

            if ($result['success']) {
                return redirect()
                    ->route('user.profile')
                    ->with('success', 'Profile picture updated.');
            }

            $photoErrors = $result['photoErrors'];
        }

        // ------------------------------------------------------------------
        // Feature 5 - Security tab: same rule set as the settings controllers.
        // PRGs to ?tab=security so the confirmation lands back on the section
        // the user was working in.
        // ------------------------------------------------------------------
        if ($request->isMethod('post') && $formAction === 'password') {
            $settingsErrors = PasswordChange::validate($request, $uid);

            if ($settingsErrors === []) {
                PasswordChange::apply($request, $uid);

                return redirect()
                    ->route('user.profile', ['tab' => 'security'])
                    ->with('success', 'Password updated successfully.');
            }
        }

        // Which of the two sections is on screen. Only one of the two error
        // bags can be non-empty at a time (each POST sets its own), so a
        // failed submit always lands on the tab holding its messages.
        $activeTab = 'profile';
        if ($formAction === 'password' && $settingsErrors !== []) {
            $activeTab = 'security';
        } elseif ($formAction === '' && $request->query('tab') === 'security') {
            $activeTab = 'security';
        }

        return view('user.profile', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'user/profile'),
            'title' => 'Account Settings',
            'data' => $data,
            'profileErrors' => $errors,
            'settingsErrors' => $settingsErrors,
            'photoErrors' => $photoErrors,
            'photoUrl' => ProfilePhoto::url($data['photo'] ?? null),
            'formAction' => $formAction,
            'activeTab' => $activeTab,
        ]);
    }

    /**
     * Original lines 21-31 query: users + profile + student/employee extras.
     *
     * Returns an assoc array; duplicate column names resolve to the LAST
     * table's value exactly like the reference's FETCH_ASSOC row (id and
     * the timestamp columns land on the profile's, which the form never
     * reads - identity comes from the authenticated user). It also carries
     * users.username/email/account_type/status, which is what the merged
     * Security tab's Account card reads - one query feeds both halves.
     */
    private function fetchRow(int $uid): array
    {
        $row = DB::selectOne(
            'SELECT u.*, p.*, sd.student_no, sd.course, sd.year_level, sd.section,
                    ed.employee_no, ed.department, ed.position
             FROM users u
             LEFT JOIN profiles p ON p.user_id = u.id
             LEFT JOIN student_details sd ON sd.user_id = u.id
             LEFT JOIN employee_details ed ON ed.user_id = u.id
             WHERE u.id = ? LIMIT 1',
            [$uid]
        );

        return (array) ($row ?? []);
    }
}

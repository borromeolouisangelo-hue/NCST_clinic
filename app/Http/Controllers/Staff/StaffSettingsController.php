<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\PasswordChange;
use App\Support\ShellContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Phase 29 - staff/settings.php (Account Settings: identity + password).
 *
 * Behind require_login(['staff']) - the staff-only account page. Same shape
 * as Phase 20's UserSettingsController with two structural differences from
 * the user variant:
 *
 *  - NO password modal: the reference inlines the form in the Security card
 *    (original lines 79-96), so the top-level error block (lines 51-53)
 *    renders UNCONDITIONALLY - there is no second block and no reopen script.
 *  - role_badge_label() (session-based) is $shell->roleBadge exactly as in
 *    the user view (ShellContext::badgeFor is the byte-for-byte port).
 *
 * The reference soft-fails an invalid CSRF into $errors (line 22-24, which
 * also skips the current-password verify at line 35 via ! $errors); the
 * port's global VerifyCsrfToken middleware rejects the same token-less POST
 * up front with 419 - the approved Phase 6 strengthening. password_hash()
 * becomes Hash::make() and password_verify() becomes Hash::check().
 */
class StaffSettingsController extends Controller
{
    /**
     * Render the Account/Security cards; POST runs the password handler.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $errors = [];
        $uid = (int) $request->user()->id;

        // Original lines 17-19: the users row for the Account card.
        $data = (array) (DB::selectOne('SELECT u.* FROM users u WHERE u.id = ? LIMIT 1', [$uid]) ?? []);

        $formAction = (string) $request->input('form_action', '');

        if ($request->isMethod('post') && $formAction === 'password') {
            // Feature 5: the three exact rules, messages and append order
            // (original lines 35-37) now live in one shared class so this
            // page, the user copy and the merged Profile + Security page
            // can never drift apart. The raw reads, the empty-guard below
            // and the update/audit/flash/PRG sequence (original lines
            // 40-44) are unchanged.
            $errors = PasswordChange::validate($request, $uid);

            if ($errors === []) {
                PasswordChange::apply($request, $uid);

                return redirect()
                    ->route('staff.settings')
                    ->with('success', 'Password updated successfully.');
            }
        }

        return view('staff.settings', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'staff/settings'),
            'title' => 'Account Settings',
            'data' => $data,
            'settingsErrors' => $errors,
        ]);
    }
}

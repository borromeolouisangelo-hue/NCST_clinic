<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\PasswordChange;
use App\Support\ShellContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Phase 20 - user/settings.php (Account Settings: identity + password).
 *
 * Behind require_login(['user']): the role:user bucket holds both students
 * and employees, so the route is role:user - admin/staff bounce to their
 * own dashboards. Personal details live on user/profile.php (the original
 * header comment splits the two pages); this one only READS users.* into
 * the Account card and runs the password-change handler from the modal.
 */
class UserSettingsController extends Controller
{
    /**
     * Render the Account/Security cards; POST runs the password handler.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $uid = (int) $request->user()->id;

        // Original lines 16-18: the users row for the Account card.
        $data = (array) (DB::selectOne('SELECT u.* FROM users u WHERE u.id = ? LIMIT 1', [$uid]) ?? []);

        $errors = [];
        $formAction = (string) $request->input('form_action', '');

        if ($request->isMethod('post') && $formAction === 'password') {
            // Original lines 21-23 soft-failed an invalid CSRF into $errors
            // (line 34 then skipped the current-password verify when any
            // error existed). The port's global VerifyCsrfToken middleware
            // rejects the same token-less POST up front with 419 - the
            // approved Phase 6 strengthening every migrated POST form gets.

            // Feature 5: the three exact rules, messages and append order
            // (original lines 34-36) now live in one shared class so this
            // page, the staff copy and the merged Profile + Security page
            // can never drift apart. The raw reads, the empty-guard below
            // and the PRG flash are unchanged.
            $errors = PasswordChange::validate($request, $uid);

            if ($errors === []) {
                PasswordChange::apply($request, $uid);

                return redirect()
                    ->route('user.settings')
                    ->with('success', 'Password updated successfully.');
            }
        }

        // Original line 49: reopen the modal only when the submit came from
        // it (form_action=password) AND the handler collected errors - the
        // top-level alert block renders only when this is false, so errors
        // never appear twice.
        return view('user.settings', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'user/settings'),
            'title' => 'Account Settings',
            'data' => $data,
            'settingsErrors' => $errors,
            'formAction' => $formAction,
            'reopen' => $formAction === 'password' && $errors !== [],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Logout - port of auth/logout.php, same order:
 *
 *  1. audit 'logout' (only when signed in - the original guards with
 *     is_logged_in() before touching the session)
 *  2. sign out, invalidate the session (old id + data destroyed), regenerate
 *     the CSRF token - the Laravel equivalent of the original's session
 *     destroy + fresh session_start()
 *  3. flash "You have been logged out."
 *  4. redirect to the login page
 *
 * The route is GET because the original logout.php is a plain <a href> (the
 * frozen account-dropdown partial links to it) - the original has no CSRF
 * token on logout either, so the method is kept identical rather than changing
 * the Phase 4 shell. The session is still fully invalidated on every hit.
 */
class LogoutController extends Controller
{
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null) {
            AuditLogger::log((int) $user->id, 'logout', 'users', (int) $user->id, 'User logged out');
        }

        Auth::logout();                      // detach the user from the guard
        $request->session()->invalidate();   // new session id, old data gone
        $request->session()->regenerateToken(); // fresh CSRF token for the login form

        return redirect()
            ->route('login')
            ->with('success', 'You have been logged out.');
    }
}

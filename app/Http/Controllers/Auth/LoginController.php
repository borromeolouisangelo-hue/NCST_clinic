<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FailedLogins;
use App\Support\ShellContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Login flow - port of auth/login.php.
 *
 * The check order is byte-identical to the original:
 *
 *  1. signed in already      -> redirect to own dashboard (original lines 7-9)
 *  2. empty username/password-> "Please enter your username and password."
 *  3. block window hit       -> "Too many failed login attempts..." (no lookup)
 *  4. lookup username OR email (grouped OR, LIMIT 1 equivalent)
 *  5. no user / bad password -> record failed attempt, "Invalid username or password."
 *  6. status !== 'active'    -> "Your account is inactive..." (original records
 *                               NO failed attempt on this branch - kept)
 *  7. success                -> clear the username's failed rows, sign in
 *                               (SessionGuard regenerates the session id =
 *                               fixation protection), audit 'login', flash
 *                               "Welcome back, {name}!", redirect to the role
 *                               dashboard.
 *
 * Credentials are checked with Hash::check() + Auth::login() - never
 * Auth::attempt() - so the existing cost-10 bcrypt hashes are verified as-is
 * and never rewritten.
 */
class LoginController extends Controller
{
    /**
     * GET /login - the login form, or a bounce to the dashboard when signed in.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user !== null) {
            return redirect()->route(ShellContext::dashboardRouteName($user->role));
        }

        return view('auth.login', [
            'schoolName' => $this->setting('school_name', config('app.name')),
            'clinicName' => $this->setting('clinic_name', 'School Health Clinic'),
        ]);
    }

    /**
     * POST /login - credential check, failed-login protection, audit, redirect.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null) {
            return redirect()->route(ShellContext::dashboardRouteName($user->role));
        }

        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');
        $ip = $request->ip();

        if ($username === '' || $password === '') {
            return $this->withErrors('Please enter your username and password.', $username);
        }

        if (FailedLogins::isBlocked($username, $ip)) {
            return $this->withErrors(
                'Too many failed login attempts. Please wait 15 minutes before trying again.',
                $username
            );
        }

        $user = User::query()
            ->with('profile')
            ->where(function ($query) use ($username) {
                $query->where('username', $username)
                    ->orWhere('email', $username);
            })
            ->first();

        if (! $user || ! Hash::check($password, $user->password_hash)) {
            FailedLogins::record($username, $ip);

            return $this->withErrors('Invalid username or password.', $username);
        }

        if ($user->status !== 'active') {
            return $this->withErrors(
                'Your account is inactive. Please contact the administrator.',
                $username
            );
        }

        FailedLogins::clear($user->username);

        Auth::login($user); // SessionGuard::login() regenerates the session id.

        AuditLogger::log((int) $user->id, 'login', 'users', (int) $user->id, 'User logged in');

        $fullName = ShellContext::fullNameFor($user);

        return redirect()
            ->route(ShellContext::dashboardRouteName($user->role))
            ->with(
                'success',
                'Welcome back, '.($fullName !== '' ? $fullName : $user->username).'!'
            );
    }

    /**
     * Redirect back to the login form with one validation error - the original
     * re-renders auth/login.php with its $errors array. Only the username is
     * flashed for repopulation; the password is never persisted anywhere.
     */
    private function withErrors(string $message, string $username): RedirectResponse
    {
        return redirect()
            ->route('login')
            ->withErrors(['login' => $message])
            ->withInput(['username' => $username]);
    }

    /**
     * get_setting($pdo, $key, $default) via the system_settings table: the
     * default applies only when the row does not exist (same as the original).
     * Read in the controller so the view never touches the database.
     */
    private function setting(string $key, string $default): string
    {
        $value = SystemSetting::query()
            ->where('setting_key', $key)
            ->value('setting_value');

        return $value === null ? $default : (string) $value;
    }
}

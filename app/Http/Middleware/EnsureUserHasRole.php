<?php

namespace App\Http\Middleware;

use App\Support\ShellContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role guard - port of require_login($allowed_roles) from includes/functions.php.
 *
 *  - Guest            -> 302 to the login page (require_login's first branch;
 *                         the `auth` middleware normally handles this first -
 *                         the fallback here keeps the guard safe standalone).
 *  - Wrong role       -> 302 to the user's OWN dashboard, never a 403 - that
 *                         is what require_login() does (it calls
 *                         redirect(dashboard_for(current_role()))).
 *
 * Registered in bootstrap/app.php as the `role` alias and always used behind
 * `auth`: ->middleware(['auth', 'role:admin']).
 */
class EnsureUserHasRole
{
    /**
     * @param  string  ...$roles  Role buckets allowed for this route (e.g. `role:admin`).
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if (! in_array($user->role, $roles, true)) {
            return redirect()->route(ShellContext::dashboardRouteName($user->role));
        }

        return $next($request);
    }
}

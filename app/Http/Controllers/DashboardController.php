<?php

namespace App\Http\Controllers;

use App\Queries\DashboardQueries;
use App\Services\Notify;
use App\Services\OverdueLoanNotifications;
use App\Support\ShellContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The three role dashboards: GET /admin/dashboard, /staff/dashboard,
 * /user/dashboard - one shared implementation, one layout, real identity.
 *
 * Routes are guarded by ['auth', 'role:...'], so a guest never reaches this
 * controller (redirected to login) and a wrong-role user is redirected to
 * their own dashboard by EnsureUserHasRole - the same two redirects
 * require_login() performs in the original application.
 *
 * Page titles are the original $page_title values. All three dashboards now
 * render their original feature content (Phases 7, 8 and 9); the Phase 5
 * scaffold view has been removed with its private render() helper.
 *
 * Phase 3 (Step 3): every data query (admin cards, staff KPI/trends,
 * renderUser counters) and the calendar event assembly moved into
 * App\Queries\DashboardQueries, injected below - this controller now reads
 * request parameters, composes responses (the sick-leave POST handler,
 * redirects) and renders views.
 */
class DashboardController extends Controller
{
    public function __construct(protected DashboardQueries $dashboardQueries) {}

    /**
     * GET / - port of index.php, the original entry-point role router:
     * logged-in visitors go to dashboard_for(current_role()), everyone
     * else to the login page (auth/login.php).
     */
    public function landing(Request $request): RedirectResponse
    {
        if ($request->user() !== null) {
            return redirect()->route(
                ShellContext::dashboardRouteName($request->user()->role)
            );
        }

        return redirect()->route('login');
    }

    /**
     * GET /admin/dashboard - port of admin/dashboard.php.
     *
     * $page_title = 'Admin Dashboard' (line 12) plus the four stat counters
     * (lines 15-18) and the three data cards (lines 22-54): Low Stock,
     * Expiring Within 90 Days and Recent Activity. Line 19's $activeLoans
     * query is dead code in the original - computed but never rendered - so
     * nothing is ported for it.
     *
     * SQL parity: every predicate is the original one, spelled with plain
     * function calls so the identical statement also runs on the isolated
     * SQLite test connection (curdate()/adddate() are registered there the
     * same way PatientTest registers concat()).
     * DATE_ADD(CURDATE(), INTERVAL 90 DAY) (line 42) is written as
     * ADDDATE(CURDATE(), 90) - the same MySQL day arithmetic, same DB clock.
     */
    public function admin(Request $request): View
    {
        // The seven data queries moved into App\Queries\DashboardQueries
        // (Phase 3, Step 3) in the original order; this method keeps request
        // parameters (none beyond the identity) and view rendering.
        $data = $this->dashboardQueries->adminData();

        return view('admin.dashboard', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'admin/dashboard'),
            'title' => 'Admin Dashboard',
            'totalUsers' => $data['totalUsers'],
            'totalStaff' => $data['totalStaff'],
            'totalMeds' => $data['totalMeds'],
            'todayRequests' => $data['todayRequests'],
            'lowStock' => $data['lowStock'],
            'expiring' => $data['expiring'],
            'recent' => $data['recent'],
            'calendarEvents' => $this->dashboardQueries->calendarEvents($request),
        ]);
    }

    /** staff/dashboard.php: $page_title = 'Clinic Dashboard'. */
    public function staff(Request $request): View
    {
        // All data queries and trend computations moved into
        // App\Queries\DashboardQueries (Phase 3, Step 3) in the original
        // order (including the computed-but-unrendered $overdue,
        // $overdueTrend and yesterday stats whose queries still run);
        // this method keeps request parameters (none beyond the identity)
        // and view rendering.
        $data = $this->dashboardQueries->staffData();

        return view('staff.dashboard', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'staff/dashboard'),
            'title' => 'Clinic Dashboard',
            'todayRequests' => $data['todayRequests'],
            'todayCons' => $data['todayCons'],
            'activeLoans' => $data['activeLoans'],
            // 'overdue' and 'overdueTrend' removed - Feature 6 replaces the
            // Overdue Loans KPI card with Pending Sick Leave Requests.
            'pendingSickLeaves' => $data['pendingSickLeaves'],
            'loanTrend' => $data['loanTrend'],
            'requestedItems' => $data['requestedItems'],
            'borrowedItems' => $data['borrowedItems'],
            'trendRanges' => $data['trendRanges'],
            'days' => $data['days'],
            'periodTotal' => $data['periodTotal'],
            'periodChange' => $data['periodChange'],
            'lowStock' => $data['lowStock'],
            // Medicine Expiring Soon: batches expiring within the next
            // 7 days (inclusive), one row per batch - the Low Stock card's
            // side-by-side partner on the dashboard.
            'expiringSoon' => $data['expiringSoon'],
            'historySections' => $data['historySections'],
            'recent' => $data['recent'],
            'calendarEvents' => $this->dashboardQueries->calendarEvents($request),
        ]);
    }

    /**
     * GET /user/dashboard - user/dashboard.php (student/employee dashboard).
     *
     * Overdue loans are swept first (original line 12) so a fresh alert is
     * already counted by the unread badge this response renders.
     */
    public function user(Request $request): View
    {
        OverdueLoanNotifications::create();

        return $this->renderUser($request);
    }

    /**
     * POST /user/dashboard - the sick-leave modal handler of
     * user/dashboard.php:16-45. The original form self-posts to the page;
     * this route keeps the same URL, the PRG redirect on success and an
     * in-place re-render with the modal's error list on failure.
     *
     * CSRF is Laravel's VerifyCsrfToken (419 on mismatch) instead of the
     * original's silent redirect - the security-stack convention since
     * Phase 6.
     */
    public function userSickLeave(Request $request): View|RedirectResponse
    {
        OverdueLoanNotifications::create();

        // Original line 18: the handler only runs for form_action=sick_leave;
        // any other POST falls through to the plain page render.
        if ((string) $request->input('form_action', '') !== 'sick_leave') {
            return $this->renderUser($request);
        }

        $reason = trim((string) $request->input('reason', ''));
        $startDate = (string) $request->input('start_date', '');
        $endDate = (string) $request->input('end_date', '');

        // Exact rules, messages and ordering of the original handler:
        // every error is collected (not first-failure), and the range check
        // is the original's string comparison of two non-empty values.
        $errors = [];
        if ($reason === '') {
            $errors[] = 'Please describe your reason for leave.';
        }
        if ($startDate === '') {
            $errors[] = 'Start date is required.';
        } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $errors[] = 'Invalid start date format.';
        }
        if ($endDate !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            $errors[] = 'Invalid end date format.';
        }
        if ($startDate !== '' && $endDate !== '' && $endDate < $startDate) {
            $errors[] = 'End date cannot be before start date.';
        }

        if ($errors === []) {
            $leaveId = DB::table('sick_leaves')->insertGetId([
                'user_id' => $request->user()->id,
                'reason' => $reason,
                'start_date' => $startDate,
                'end_date' => $endDate !== '' ? $endDate : null,
                'status' => 'pending',
            ]);

            // fire_event('sick_leave.created', ...) had no listener in the
            // original. Feature 2 writes the alert the success flash has
            // always promised ("The clinic staff has been notified") and
            // scopes it to clinic staff accounts - the requester is the
            // subject of the request, not a recipient of it. Deduped on the
            // leave id, preference honours the sick_leave switch.
            Notify::toRoles(['staff'], 'sick_leave', [
                'reference_id' => $leaveId,
                'severity' => 'warning',
                'title' => 'New Sick Leave Request',
                'message' => 'A new sick leave request was submitted by '.$request->user()->username
                    ." ({$startDate}".($endDate !== '' ? " to {$endDate}" : '').').',
                'action_url' => 'staff/sick_leaves',
            ]);

            return redirect()
                ->route('user.dashboard')
                ->with('success', 'Sick leave reported. The clinic staff has been notified.');
        }

        return $this->renderUser($request, $errors);
    }

    /**
     * Build the user dashboard's data and render it.
     *
     * @param  array<int, string>  $errors  Sick-leave modal messages
     *                                      (original $sickLeaveErrors).
     */
    private function renderUser(Request $request, array $errors = []): View
    {
        $user = $request->user();
        $uid = $user->id;

        $shell = ShellContext::fromUser($user, activePath: 'user/dashboard');

        // The count queries (including the Feature 3 safeCount shims) moved
        // into App\Queries\DashboardQueries (Phase 3, Step 3) in the original
        // order; this method keeps the identity/shell and view rendering.
        $data = $this->dashboardQueries->userData($uid);

        return view('user.dashboard', [
            'shell' => $shell,
            'title' => 'My Dashboard',
            'isEmployee' => $shell->isEmployee,
            'unreadNotifCount' => $data['unreadNotifCount'],
            'recentSickLeaves' => $data['recentSickLeaves'],
            'reqCount' => $data['reqCount'],
            'loanCount' => $data['loanCount'],
            'consultationCount' => $data['consultationCount'],
            'activeLoanCount' => $data['activeLoanCount'],
            'hasMedRec' => $data['hasMedRec'],
            'allergyCount' => $data['allergyCount'],
            'sickLeaveErrors' => $errors,
            'calendarEvents' => $this->dashboardQueries->calendarEvents($request),
            // Feature 3: Additional statistics
            'dispensedCount' => $data['dispensedCount'],
            'overdueLoanCount' => $data['overdueLoanCount'],
            'pendingSickLeaves' => $data['pendingSickLeaves'],
            'approvedSickLeaves' => $data['approvedSickLeaves'],
            'rejectedSickLeaves' => $data['rejectedSickLeaves'],
            'activityCount' => $data['activityCount'],
            'certificateCount' => $data['certificateCount'],
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Queries\DashboardQueries;
use App\Support\ShellContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The full-page Calendar for all three roles: GET /admin/calendar,
 * /staff/calendar and /user/calendar.
 *
 * Feature 10 only ever shipped the compact dashboard calendar card
 * (layouts/partials/calendar.blade.php + assets/js/calendar.js); the sidebar
 * partial rendered Calendar links for admin and user whose routes were never
 * defined, so they 404'd. One shared action renders the same partial in
 * full-page mode, so there is exactly one calendar implementation, one
 * FullCalendar instance per page (calendar.js picks up the single
 * #dashboardCalendar element) and one event source:
 * DashboardQueries::calendarEvents(), which already scopes events by the
 * authenticated user's role (admin: pending sick leaves, low stock, expiring
 * meds, overdue equipment; staff: today's consultations, pending sick leaves,
 * due equipment, low stock; user: own consultations and sick leaves).
 *
 * Each route carries its own ['auth', 'role:...'] middleware pair - the same
 * two redirects require_login() performs - so a wrong-role visit never
 * reaches this controller.
 */
class CalendarController extends Controller
{
    public function __construct(protected DashboardQueries $dashboardQueries) {}

    /**
     * GET /{admin|staff|user}/calendar - month/week/list FullCalendar page
     * with prev/next/today navigation and the role's own events.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('calendar', [
            'shell' => ShellContext::fromUser(
                $user,
                activePath: match ($user->role) {
                    'admin' => 'admin/calendar',
                    'staff' => 'staff/calendar',
                    default => 'user/calendar',
                },
            ),
            'calendarEvents' => $this->dashboardQueries->calendarEvents($request),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Queries\DashboardQueries;
use App\Support\ShellContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;


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

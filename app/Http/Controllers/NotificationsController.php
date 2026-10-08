<?php

namespace App\Http\Controllers;

use App\Support\ShellContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Notification centre - notifications.php (all roles, bare require_login()).
 *
 * GET renders up to 100 rows for the CURRENT user only (user_id scoped,
 * created_at DESC), optionally narrowed by ?filter=unread; the All/Unread
 * toggle buttons keep the RAW filter string, so junk values leave both
 * buttons in their outline state (reference quirk kept). POST handles
 * mark_all_read / mark_read with the reference's PRG redirects; unknown or
 * missing action values fall through and render the page (original lines
 * 19-34 semantics - no PRG for them). mark_read only touches rows owned by
 * the caller (id + user_id + is_read=0 guard, functions.php:272), and id=0
 * skips the UPDATE but still redirects (original lines 27-33).
 *
 * The reference's CSRF soft-fail (flash + redirect) runs as 419 here -
 * approved Phase 6 strengthening. $totalCount (original lines 49-51) never
 * reaches the view - dropped per the Phase 22/27 dead-query precedent.
 *
 * route: notifications, auth only (no role middleware - open to all four),
 * match GET+POST.
 */
class NotificationsController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $uid = (int) $request->user()->id;

        if ($request->isMethod('post')) {
            $action = (string) $request->input('action', '');

            if ($action === 'mark_all_read') {
                $count = $this->markAllAsRead($uid);

                return redirect()
                    ->route('notifications')
                    ->with('success', "Marked {$count} notification(s) as read.");
            }

            if ($action === 'mark_read') {
                $id = (int) $request->input('id', 0);
                if ($id > 0) {
                    $this->markAsRead($id, $uid);
                }

                return redirect()->route('notifications');
            }
            // Unknown/missing action falls through and renders - original L19-34.
        }

        $filter = (string) $request->query('filter', 'all');

        // WHERE building mirrors the reference ($where .= on filter=unread).
        $notifications = array_map(
            static fn ($row): array => (array) $row,
            DB::select(
                'SELECT * FROM notifications WHERE user_id = ?'
                .($filter === 'unread' ? ' AND is_read = 0' : '')
                .' ORDER BY created_at DESC LIMIT 100',
                [$uid]
            )
        );

        // get_unread_count() port (functions.php:244) - drives the Unread
        // button badge and the conditional Mark All Read form.
        $unreadCount = (int) DB::scalar(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0',
            [$uid]
        );

        return view('notifications', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'notifications'),
            'title' => 'Notifications',
            'filter' => $filter,
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
        ]);
    }

    /**
     * mark_notification_as_read() port (functions.php:272): only this user's
     * still-unread row flips; other users' rows are untouchable.
     */
    private function markAsRead(int $id, int $uid): void
    {
        DB::update(
            'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ? AND is_read = 0',
            [$id, $uid]
        );
    }

    /**
     * mark_all_notifications_as_read() port (functions.php:288): returns the
     * affected-row count for the "Marked N notification(s) as read." flash.
     */
    private function markAllAsRead(int $uid): int
    {
        return (int) DB::update(
            'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0',
            [$uid]
        );
    }
}

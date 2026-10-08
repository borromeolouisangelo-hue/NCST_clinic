<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\Notify;
use App\Support\ShellContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Phase 13 - Sick leave review queue (staff).
 *
 * Ports staff/sick_leaves.php: the do=review POST dispatch (original lines
 * 16-36), the search/filter builder (12-13, 38-51), the list query with its
 * four LEFT JOINs and LIMIT 100 (53-66) and the three global summary counts
 * (69-71).
 *
 * Legacy behaviours preserved:
 *  - the dispatch reads the POST body only (the original reads $_POST), so
 *    a GET ?do=review never reaches the handler;
 *  - status accepts only the exact strings 'approved'/'rejected'; anything
 *    else - including the missing field - flashes "Invalid review action."
 *    without touching the database;
 *  - a well-formed review of a non-existent id still flashes the success
 *    message (the original's UPDATE matches 0 rows and never checks);
 *  - reviewed_at uses MySQL NOW() (DB clock) like the original;
 *  - there is NO audit row: the original page never calls log_action();
 *  - fire_event('sick_leave.reviewed') has no listener (grep of the
 *    reference finds only the call itself), so nothing is written;
 *  - an invalid ?status= value is silently ignored by the SQL (all rows
 *    show) while the <select> just renders no selected option;
 *  - search matches CONCAT(first_name,' ',last_name) OR employee_no;
 *  - counts are always global, unaffected by search/filter.
 *
 * Approved differences: CSRF mismatch raises Laravel's 419 instead of the
 * original's flash+redirect (Phase 6 convention) - the handler is equally
 * protected because middleware runs first; success/danger flashes surface
 * through the shared flash partial; rows are stdClass objects (->x).
 */
class SickLeaveController extends Controller
{
    /**
     * GET|POST /staff/sick_leaves - staff/sick_leaves.php.
     */
    public function index(Request $request): View|RedirectResponse
    {
        // ---- Review action (original lines 16-36) -------------------------
        // The original validates CSRF first (16-19, redirect exits), then
        // dispatches on $_POST['do'] (22). Laravel's VerifyCsrfToken
        // middleware covers the first half with a 419; this block mirrors
        // the dispatch on the POST body only, exactly like $_POST.
        if ($request->isMethod('post') && $request->request->get('do') === 'review') {
            $leaveId = (int) $request->request->get('leave_id', 0);
            $statusInput = $request->request->get('status');
            $status = in_array($statusInput, ['approved', 'rejected'], true) ? $statusInput : '';
            $note = trim((string) $request->request->get('review_note'));

            if ($leaveId > 0 && $status !== '') {
                $leave = DB::table('sick_leaves')->where('id', $leaveId)->first();

                DB::table('sick_leaves')->where('id', $leaveId)->update([
                    'status' => $status,
                    'reviewed_by' => (int) $request->user()->id,
                    'review_note' => $note !== '' ? $note : null,
                    'reviewed_at' => DB::raw('NOW()'),
                ]);

                // fire_event('sick_leave.reviewed', ...) had no listener in the
                // reference; feature 2 tells the requester, and only them, that
                // their own request changed state (deduped on the leave id, so
                // re-reviewing the same request cannot stack alerts).
                if ($leave) {
                    $leaveFrom = trim((string) ($leave->start_date ?? ''));
                    $leaveTo = trim((string) ($leave->end_date ?? ''));
                    $window = $leaveFrom !== ''
                        ? ($leaveTo !== '' ? "{$leaveFrom} to {$leaveTo}" : $leaveFrom)
                        : '';
                    $approved = $status === 'approved';

                    Notify::to((int) $leave->user_id, 'sick_leave_status', [
                        'reference_id' => $leaveId,
                        'severity' => $approved ? 'success' : 'danger',
                        'title' => $approved ? 'Sick Leave Approved' : 'Sick Leave Declined',
                        'message' => ($approved ? 'Your sick leave request' : 'Your sick leave request')
                            .($window !== '' ? " ({$window})" : '')
                            .($approved ? ' was approved by the clinic staff.' : ' was declined by the clinic staff.')
                            .($note !== '' ? " Note: {$note}" : ''),
                        'action_url' => 'user/my_requests',
                    ]);
                }

                return redirect()
                    ->route('staff.sick_leaves')
                    ->with('success', 'Sick leave request '.$status.'.');
            }

            return redirect()
                ->route('staff.sick_leaves')
                ->with('danger', 'Invalid review action.');
        }

        // ---- Search + filter (original lines 12-13, 38-51) ---------------
        $search = trim((string) $request->query('q', ''));
        $statusFilter = $request->query('status', 'all');

        $where = '';
        $params = [];

        if ($search !== '') {
            $where = " WHERE (CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR ed.employee_no LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        if (in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
            $where .= ($where ? ' AND' : ' WHERE').' sl.status = ?';
            $params[] = $statusFilter;
        }

        // ---- List (original lines 53-66) ---------------------------------
        $leaves = DB::select(
            "SELECT sl.*, CONCAT(p.first_name, ' ', p.last_name) AS employee_name,
                    ed.department, ed.position, ed.employee_no,
                    CONCAT(rp.first_name, ' ', rp.last_name) AS reviewer_name
             FROM sick_leaves sl
             LEFT JOIN profiles p ON p.user_id = sl.user_id
             LEFT JOIN employee_details ed ON ed.user_id = sl.user_id
             LEFT JOIN profiles rp ON rp.user_id = sl.reviewed_by
             $where
             ORDER BY sl.created_at DESC
             LIMIT 100",
            $params,
        );

        // ---- Counts (original lines 69-71) - always global ---------------
        $pendingCount = (int) DB::table('sick_leaves')->where('status', 'pending')->count();
        $approvedCount = (int) DB::table('sick_leaves')->where('status', 'approved')->count();
        $rejectedCount = (int) DB::table('sick_leaves')->where('status', 'rejected')->count();

        return view('staff.sick_leaves', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'staff/sick_leaves'),
            'title' => 'Sick Leave Requests',
            'search' => $search,
            // The Filter <select> reads the RAW ?status= (missing -> 'all'),
            // exactly like the original's `$_GET['status'] ?? 'all'`.
            'statusFilter' => $statusFilter,
            'leaves' => $leaves,
            'pendingCount' => $pendingCount,
            'approvedCount' => $approvedCount,
            'rejectedCount' => $rejectedCount,
        ]);
    }
}

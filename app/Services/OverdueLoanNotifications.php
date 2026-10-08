<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Port of create_overdue_loan_notifications() (includes/functions.php:201).
 *
 * Called from user/dashboard.php (line 12) on every page load - and later
 * from staff/equipment_loans.php (Phase 17) - before any unread count is
 * read, so a freshly-created overdue alert is part of the same response.
 *
 * Behaviour mirrors the original exactly:
 *
 *  - find loans that are still 'borrowed', have a due_date, and are past it;
 *  - for each, insert one 'overdue_loan' notification for the borrower -
 *    but only when that (user_id, loan_id, type) row does not exist yet, so
 *    repeated page loads never duplicate the alert;
 *  - the whole thing is wrapped in try/catch and fails silently: a broken
 *    notification write must never break the page that triggered it.
 *
 * Feature 2 addition: the loan form never writes due_date, so on live data
 * every borrowed row has due_date = NULL and the sweep above can never
 * fire - the alerts in the UI stayed silent while the page's own "Overdue"
 * filter (borrowed_at <= SUBDATE(NOW(), 1), the same expression this class
 * now uses) still called those loans overdue. The query therefore treats a
 * NULL due_date as "overdue once it has been out for a day", reuses the
 * borrow date in the message, and honours the equipment_overdue switch via
 * Notify::enabled(). The (user_id, loan_id, type) existence check - the
 * dedup half of the feature - is unchanged.
 *
 * SQL keeps CURDATE() - registered as a SQLite shim in tests/TestCase.php -
 * so the same statement runs on both engines.
 */
class OverdueLoanNotifications
{
    public static function create(): void
    {
        try {
            $overdueLoans = DB::select(
                "SELECT el.id, el.user_id, el.equipment_id, el.due_date, el.borrow_date,
                        e.name as equipment_name
                 FROM equipment_loans el
                 JOIN equipment e ON e.id = el.equipment_id
                 WHERE el.status = 'borrowed'
                   AND (
                        (el.due_date IS NOT NULL AND el.due_date < CURDATE())
                        OR (el.due_date IS NULL AND el.borrowed_at <= SUBDATE(NOW(), 1))
                       )"
            );

            foreach ($overdueLoans as $loan) {
                $existing = DB::table('notifications')
                    ->where('user_id', $loan->user_id)
                    ->where('loan_id', $loan->id)
                    ->where('type', 'overdue_loan')
                    ->exists();

                if ($existing || ! Notify::enabled((int) $loan->user_id, 'overdue_loan')) {
                    continue;
                }

                $title = 'Overdue Equipment Loan';
                if ($loan->due_date !== null && $loan->due_date !== '') {
                    $message = "Your loan of '{$loan->equipment_name}' was due on "
                        .date('M d, Y', strtotime($loan->due_date))
                        .' and is now overdue. Please return it to the clinic.';
                } else {
                    // due_date is never written by the loan form - fall back to
                    // the date it was taken out so the alert still reads true.
                    $borrowedOn = trim((string) ($loan->borrow_date ?? ''));
                    $message = "Your loan of '{$loan->equipment_name}', borrowed on "
                        .($borrowedOn !== '' && strtotime($borrowedOn) !== false
                            ? date('M d, Y', strtotime($borrowedOn))
                            : 'an earlier date')
                        .', is now overdue. Please return it to the clinic.';
                }

                DB::table('notifications')->insert([
                    'user_id' => $loan->user_id,
                    'loan_id' => $loan->id,
                    'type' => 'overdue_loan',
                    'severity' => 'warning',
                    'title' => $title,
                    'message' => $message,
                    'is_read' => 0,
                    'created_at' => DB::raw('NOW()'),
                ]);
            }
        } catch (\Throwable $e) {
            // Silently fail - notification creation is non-critical.
            error_log('create_overdue_loan_notifications error: '.$e->getMessage());
        }
    }
}

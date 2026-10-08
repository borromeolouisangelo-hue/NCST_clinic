<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 34 - notification queries behind the api/* endpoints.
 *
 * Ports of (same SQL, query builder instead of hand-written PDO):
 *   - includes/functions.php:244 get_unread_count()
 *   - includes/functions.php:257 get_recent_notifications()
 *   - includes/functions.php:272 mark_notification_as_read()
 *   - includes/functions.php:288 mark_all_notifications_as_read()
 *   - includes/NotificationModel.php:110 getUnreadCount()
 *   - includes/NotificationModel.php:116 getSince()
 *   - includes/NotificationModel.php:124 getLatestTimestamp()
 *   - includes/NotificationModel.php:37  processQueue()
 *     (driven by api/process_notification_queue.php)
 *
 * Rows are returned as plain arrays via (array) cast so json_encode() sees
 * the raw column order of `SELECT *` and the driver's native value types -
 * Laravel and the reference both run PDO with ATTR_EMULATE_PREPARES=false
 * and ATTR_STRINGIFY_FETCHES=false, so the emitted JSON matches byte for
 * byte. try/catch fallbacks mirror the original helpers' PDOException
 * paths (they answer 0 / [] / false instead of erroring).
 *
 * Timestamps inside processQueue() use the raw NOW() expression exactly
 * like the reference SQL: MySQL's clock (SYSTEM timezone) and PHP's
 * app timezone disagree in this environment, so the DB clock wins.
 */
class NotificationQueries
{
    /**
     * functions.php:244 / NotificationModel.php:110 - unread badge count.
     * get_unread_count() catches PDOException and answers 0; the model
     * variant does not, but the table is part of the frozen schema, so the
     * two failure modes are unreachable in practice - one guarded port
     * covers both call sites.
     */
    public static function unreadCount(int $userId): int
    {
        try {
            return (int) DB::table('notifications')
                ->where('user_id', $userId)
                ->where('is_read', 0)
                ->count();
        } catch (QueryException $e) {
            return 0;
        }
    }

    /**
     * functions.php:257 - SELECT * newest-first, bound LIMIT, optional
     * unread filter. Garbage limits arrive as 0 (LIMIT 0 -> no rows) and a
     * negative limit raises on MySQL -> the catch answers [] like the
     * reference's helper.
     */
    public static function recent(int $userId, int $limit, bool $unreadOnly): array
    {
        try {
            $query = DB::table('notifications')->where('user_id', $userId);

            if ($unreadOnly) {
                $query->where('is_read', 0);
            }

            return $query->orderByDesc('created_at')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        } catch (QueryException $e) {
            return [];
        }
    }

    /** functions.php:272 - owner-scoped mark-one; true when a row changed. */
    public static function markOne(int $notificationId, int $userId): bool
    {
        try {
            return DB::table('notifications')
                ->where('id', $notificationId)
                ->where('user_id', $userId)
                ->where('is_read', 0)
                ->update(['is_read' => 1]) > 0;
        } catch (QueryException $e) {
            return false;
        }
    }

    /** functions.php:288 - mark every unread row of one user; answers how many changed. */
    public static function markAll(int $userId): int
    {
        try {
            return DB::table('notifications')
                ->where('user_id', $userId)
                ->where('is_read', 0)
                ->update(['is_read' => 1]);
        } catch (QueryException $e) {
            return 0;
        }
    }

    /** NotificationModel.php:116 - backlog for an EventSource Last-Event-ID reconnect (no catch: the model throws). */
    public static function since(int $userId, string $since, int $limit = 20): array
    {
        return DB::table('notifications')
            ->where('user_id', $userId)
            ->where('created_at', '>', $since)
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /** NotificationModel.php:124 - newest created_at, null when the user has none. */
    public static function latestTimestamp(int $userId): ?string
    {
        $val = DB::table('notifications')->where('user_id', $userId)->max('created_at');

        return $val ?: null;
    }

    /**
     * NotificationModel.php:37 - drain pending notification_queue rows into
     * notifications (api/process_notification_queue.php). Each row moves
     * atomically: the notification insert and the queue status update
     * commit together, so a crash cannot duplicate or drop a notification.
     * Invalid severities collapse to 'info' before the insert (the queue
     * column is an enum on MySQL, a plain string port-side), and a failed
     * row is marked 'failed' - best effort, leaving it pending when even
     * that cannot be written so the next run retries it.
     */
    public static function processQueue(int $limit = 100): int
    {
        $limit = max(1, $limit);
        $validSeverities = ['info', 'success', 'warning', 'danger'];

        try {
            $rows = DB::table('notification_queue')
                ->select('id', 'user_id', 'type', 'title', 'message', 'action_url', 'severity', 'icon', 'reference_id')
                ->where('status', 'pending')
                ->orderBy('id')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        } catch (QueryException $e) {
            // Queue table missing or unreadable - nothing to process.
            return 0;
        }

        $delivered = 0;

        foreach ($rows as $row) {
            try {
                DB::transaction(function () use ($row, $validSeverities) {
                    DB::table('notifications')->insert([
                        'user_id' => $row['user_id'],
                        'type' => $row['type'],
                        'severity' => in_array($row['severity'], $validSeverities, true) ? $row['severity'] : 'info',
                        'icon' => $row['icon'],
                        'title' => $row['title'],
                        'message' => $row['message'],
                        'action_url' => $row['action_url'],
                        'reference_id' => $row['reference_id'],
                        'is_read' => 0,
                        'created_at' => DB::raw('NOW()'),
                    ]);

                    DB::table('notification_queue')
                        ->where('id', $row['id'])
                        ->update([
                            'status' => 'processed',
                            'processed_at' => DB::raw('NOW()'),
                        ]);
                });

                $delivered++;
            } catch (QueryException $e) {
                try {
                    DB::table('notification_queue')
                        ->where('id', $row['id'])
                        ->update([
                            'status' => 'failed',
                            'processed_at' => DB::raw('NOW()'),
                        ]);
                } catch (QueryException $ignored) {
                    // Leave the row pending so the next run retries it.
                }
            }
        }

        return $delivered;
    }
}

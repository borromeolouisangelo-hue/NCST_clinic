<?php

namespace App\Services;

use App\Models\FailedLogin;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Failed-login protection - port of record_failed_login() /
 * is_login_blocked() / clear_failed_logins() from includes/auth.php, written
 * against the REAL `failed_logins` schema (id, username, ip_address,
 * attempted_at).
 *
 * NOTE (original-app bug, reported with Phase 5): the original INSERT
 * references columns that do not exist in the live schema (attempt_time /
 * attempt_count / last_attempt), so it always threw and was swallowed by the
 * catch - the original application has never actually recorded a failed
 * attempt, which means its own COUNT()-based block check has never fired. This
 * port writes the columns that DO exist (attempted_at keeps its
 * DEFAULT CURRENT_TIMESTAMP, stamped by the MySQL clock), so the original's
 * blocking query works exactly as designed. No schema changes, no invented
 * columns - the table is used as-is.
 *
 * All window math runs on the MySQL clock (DATE_SUB(NOW(), ...)), identical to
 * the original query, so the 15-minute window can never drift between PHP and
 * MySQL. Any error fails OPEN (returns false), which is exactly what the
 * original's catch (PDOException) does.
 */
class FailedLogins
{
    /** Attempts inside the window before the username+IP pair is blocked. */
    public const MAX_ATTEMPTS = 5;

    /** Sliding window length in minutes (same as the original query). */
    public const WINDOW_MINUTES = 15;

    /**
     * record_failed_login() - one row per failed attempt (username + IP).
     *
     * attempted_at is intentionally omitted: the column's
     * DEFAULT CURRENT_TIMESTAMP stamps it with the same MySQL clock the block
     * window reads back.
     */
    public static function record(string $username, ?string $ip): void
    {
        try {
            FailedLogin::create([
                'username' => $username,
                'ip_address' => $ip,
            ]);
        } catch (Throwable) {
            // Silently fail - audit/protection bookkeeping is non-critical,
            // exactly like the original's catch (PDOException).
        }
    }

    /** clear_failed_logins() - deletes every row for the username (any IP). */
    public static function clear(string $username): void
    {
        try {
            FailedLogin::where('username', $username)->delete();
        } catch (Throwable) {
            // Silently fail - same as the original.
        }
    }

    /**
     * is_login_blocked() - same username + IP + 15-minute MySQL window, >= 5.
     *
     * On the isolated SQLite test connection DATE_SUB() does not exist; the
     * query throws, the catch returns false and the check fails open - the
     * original fails open identically whenever its query errors. The window
     * logic itself is exercised against MySQL in the browser test matrix.
     */
    public static function isBlocked(string $username, ?string $ip): bool
    {
        try {
            return FailedLogin::query()
                ->where('username', $username)
                ->where('ip_address', $ip)
                ->where('attempted_at', '>', DB::raw(
                    'DATE_SUB(NOW(), INTERVAL '.self::WINDOW_MINUTES.' MINUTE)'
                ))
                ->count() >= self::MAX_ATTEMPTS;
        } catch (Throwable) {
            return false;
        }
    }
}

<?php

namespace App\Services;

use App\Models\AuditLog;
use Throwable;

/**
 * Audit-log writer - port of log_action() from includes/auth.php.
 *
 * Inserts exactly the columns the original inserts: user_id, action,
 * table_name, record_id, details.
 *
 *  - `created_at` is left to the column's DEFAULT CURRENT_TIMESTAMP, i.e. the
 *    same MySQL clock as the original's explicit NOW().
 *  - `ip_address` is left NULL because the original's INSERT never sets it
 *    (its signature has no IP parameter). This is deliberate parity: audit rows
 *    written by Phase 5 look byte-identical to rows the original app writes.
 *    The choice is reported with the Phase 5 results.
 *
 * Failures are swallowed (audit logging is non-critical) - same as the
 * original's catch (PDOException).
 */
class AuditLogger
{
    /**
     * Write one audit_logs row.
     *
     * @param  string  $action  'login' | 'logout' (original action naming).
     * @param  string|null  $table  Table the action targets ('users' for
     *                              auth; nullable because the reference's
     *                              log_action() is untyped and admin/backup.php
     *                              is the one caller passing null/null).
     */
    public static function log(
        ?int $userId,
        string $action,
        ?string $table,
        ?int $recordId,
        string $details,
    ): void {
        try {
            AuditLog::create([
                'user_id' => $userId,
                'action' => $action,
                'table_name' => $table,
                'record_id' => $recordId,
                'details' => $details,
            ]);
        } catch (Throwable) {
            // Silently fail - audit logging is non-critical.
        }
    }
}

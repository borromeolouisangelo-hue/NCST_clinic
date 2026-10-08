<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Feature 2 - notification emission (scoping + dedup + preferences).
 *
 * The read side of the notification stack (list, bell, SSE, unread badge) has
 * always been user_id scoped; the write side only ever existed for the
 * overdue-loan sweep, so the eleven switches on Notification Preferences had
 * nothing behind them. This class is the single seam every event uses:
 *
 *  - SCOPE     one row per recipient, and a recipient is either an explicit
 *              user id or a set of roles (toRoles()). Nothing is ever
 *              broadcast to every account.
 *  - DEDUP     a (user_id, type, reference_id) row is written at most once,
 *              so a retried/replayed action - or a second page load - can
 *              never duplicate an alert. Loans dedup on loan_id, and a
 *              payload with no id at all falls back to (title, message).
 *  - PREFS     notification_settings is read per recipient: no row means
 *              enabled (the reference's `$prefs[$type] ?? true`), an explicit
 *              0 means the recipient opted out.
 *  - SAFETY    a notification must never break the request that triggered it:
 *              every path is wrapped and answers false/0 instead of throwing,
 *              matching OverdueLoanNotifications' silent-failure contract.
 *
 * Column width mirrors the live table (title varchar(150), icon varchar(50),
 * action_url varchar(255), type varchar(60)) so an over-long message cannot
 * trip MySQL's strict mode.
 */
class Notify
{
    /** The live `severity` enum members. Anything else collapses to info. */
    private const SEVERITIES = ['info', 'success', 'warning', 'danger'];

    /**
     * Row type -> preference switch key. Only the legacy overdue row type
     * differs from its switch (Notification Preferences calls it
     * equipment_overdue; the sweep has always written overdue_loan).
     */
    private const PREFERENCE_KEY = [
        'overdue_loan' => 'equipment_overdue',
    ];

    /**
     * Is `$userId` opted in to `$type`? No row and a missing table both read
     * as enabled - preferences are opt-out, so failing open keeps the old
     * behaviour on schemas that predate notification_settings.
     */
    public static function enabled(int $userId, string $type): bool
    {
        if ($userId <= 0) {
            return false;
        }

        try {
            $key = self::PREFERENCE_KEY[$type] ?? $type;
            $value = DB::table('notification_settings')
                ->where('user_id', $userId)
                ->where('notification_type', $key)
                ->value('is_enabled');

            return $value === null ? true : (int) $value === 1;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Write one notification for one recipient.
     *
     * $payload keys: title, message (both required), reference_id, loan_id,
     * severity, icon, action_url. Returns true only when a row was inserted.
     */
    public static function to(int $userId, string $type, array $payload = []): bool
    {
        if ($userId <= 0 || $type === '') {
            return false;
        }

        $title = trim((string) ($payload['title'] ?? ''));
        $message = trim((string) ($payload['message'] ?? ''));

        if ($title === '' || $message === '') {
            return false;
        }

        try {
            if (! self::enabled($userId, $type)) {
                return false;
            }

            $referenceId = self::idOrNull($payload['reference_id'] ?? null);
            $loanId = self::idOrNull($payload['loan_id'] ?? null);

            $duplicate = DB::table('notifications')
                ->where('user_id', $userId)
                ->where('type', $type);
            if ($referenceId !== null) {
                $duplicate->where('reference_id', $referenceId);
            } elseif ($loanId !== null) {
                $duplicate->where('loan_id', $loanId);
            } else {
                $duplicate->where('title', $title)->where('message', $message);
            }

            if ($duplicate->exists()) {
                return false;
            }

            $severity = $payload['severity'] ?? '';
            if (! in_array($severity, self::SEVERITIES, true)) {
                $severity = 'info';
            }

            DB::table('notifications')->insert([
                'user_id' => $userId,
                'loan_id' => $loanId,
                'reference_id' => $referenceId,
                'type' => $type,
                'severity' => $severity,
                'icon' => isset($payload['icon']) ? mb_substr((string) $payload['icon'], 0, 50) : null,
                'title' => mb_substr($title, 0, 150),
                'message' => $message,
                'action_url' => isset($payload['action_url']) && $payload['action_url'] !== ''
                    ? mb_substr((string) $payload['action_url'], 0, 255)
                    : null,
                'is_read' => 0,
                'created_at' => DB::raw('NOW()'),
            ]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Fan the same payload out to every active account with one of `$roles`.
     * Returns how many rows were actually written.
     */
    public static function toRoles(array $roles, string $type, array $payload = []): int
    {
        try {
            $ids = DB::table('users')
                ->whereIn('role', $roles)
                ->pluck('id');
        } catch (\Throwable $e) {
            return 0;
        }

        $created = 0;
        foreach ($ids as $id) {
            if (self::to((int) $id, $type, $payload)) {
                $created++;
            }
        }

        return $created;
    }

    /** Numeric ids only - notifications.reference_id is INT(11) on the live schema. */
    private static function idOrNull($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value ?: null;
    }
}

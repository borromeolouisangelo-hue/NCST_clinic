<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Per-medicine daily request limit: one request per user per specific
 * tablet/capsule medicine per calendar day. This replaces the old global
 * guard (recent_medicine_request_conflict) that blocked the same medicine -
 * or the same purpose text on unrelated medicines - within a rolling
 * 24-hour window for every inventory item.
 *
 * Eligibility is derived from the medicines.unit dosage-form column - the
 * same free-text field StockUnits::method() classifies tracking by (the
 * schema has no dedicated dosage_form column, and unit defaults to
 * 'tablet'). Only 'tablet' and 'capsule' units are limited, so first-aid
 * supplies (box, roll, tube, bottle, jar, piece, ...) are never blocked by
 * this rule. Names are never inspected.
 *
 * The window is a calendar day on the database clock:
 * DATE(requested_at) = CURDATE() - matching the app's SQL-clock convention
 * (MySQL NOW()/CURDATE() in production; the same statements run through the
 * SQLite shims in tests/TestCase.php).
 */
class MedicineDailyLimit
{
    /** Units (dosage forms) subject to the one-request-per-day limit. */
    public const LIMIT_UNITS = ['tablet', 'tablets', 'capsule', 'capsules'];

    /**
     * True when a unit (dosage form) falls under the daily limit:
     * tablets and capsules only - case-insensitive, plurals tolerated.
     */
    public static function appliesToUnit($unit): bool
    {
        return in_array(strtolower(trim((string) $unit)), self::LIMIT_UNITS, true);
    }

    /**
     * True when the user already has a request for this specific medicine
     * dated today - the calendar day, not a rolling window. Any recorded
     * status counts (dispensed or rejected): the request was made today.
     */
    public static function alreadyRequestedToday(int $userId, int $medicineId): bool
    {
        if ($userId <= 0 || $medicineId <= 0) {
            return false;
        }

        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM medicine_requests
             WHERE user_id = ? AND medicine_id = ? AND DATE(requested_at) = CURDATE()
             LIMIT 1',
            [$userId, $medicineId],
        );

        return (int) ($row->c ?? 0) > 0;
    }

    /**
     * Distinct medicine ids the user already requested today, for the
     * dispense form's per-item markers (the caller filters to eligible
     * units - the limit itself is enforced server-side on POST).
     *
     * @return array<int, int>
     */
    public static function requestedMedicineIdsToday(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $rows = DB::select(
            'SELECT DISTINCT medicine_id FROM medicine_requests
             WHERE user_id = ? AND DATE(requested_at) = CURDATE()',
            [$userId],
        );

        return array_map(fn ($row) => (int) $row->medicine_id, $rows);
    }
}

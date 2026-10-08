<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * System-settings writes - extracted verbatim from
 * App\Http\Controllers\Admin\AdminSettingsController::index()
 * (Phase 5, Group B).
 *
 * The controller keeps HTTP concerns: the body-bag reads (mirroring the
 * reference's $_POST via $request->request->has()/->get() - body bag only,
 * presence-keyed, empty/null values kept), the settings load for the view,
 * the PRG redirect and rendering. This service owns the domain:
 *
 *  - ALLOWED: the reference's 8 whitelisted setting keys, verbatim in
 *    original order - the controller iterates it for the reads, this
 *    service iterates it for the writes, so nothing outside the whitelist
 *    can ever be upserted;
 *
 *  - save(): the blind per-key upsert loop (NO validation - the
 *    reference's $errors array is declared but never populated) and the
 *    'update_settings' audit row. INSERT ... ON DUPLICATE KEY UPDATE
 *    setting_value = VALUES(setting_value) - the query builder's upsert
 *    compiles to exactly that on MySQL (ON CONFLICT DO UPDATE on the
 *    SQLite test connection), keyed on setting_key's unique index.
 *    No transaction exists on this page in the reference - none is added.
 *
 * Write order and payloads are byte-identical to the controller's
 * original; only $request->user()->id became the injected $actor (same
 * value, resolved once by the controller).
 */
class AdminSettingsService
{
    /**
     * The reference's whitelisted setting keys (original order).
     */
    public const ALLOWED = [
        'school_name', 'clinic_name', 'clinic_address', 'clinic_contact',
        'current_school_year', 'current_semester',
        'default_dispense_limit', 'default_equipment_loan_limit',
    ];

    /**
     * Upsert every whitelisted key present in the controller-read map, then
     * write the audit row.
     *
     * @param  array<string, string>  $values  key => trimmed value, built by
     *                                         the controller from the body
     *                                         bag for the ALLOWED keys only
     */
    public function save(array $values, int $actor): void
    {
        foreach (self::ALLOWED as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }
            $val = $values[$key];

            // INSERT ... ON DUPLICATE KEY UPDATE setting_value =
            // VALUES(setting_value) - the query builder's upsert compiles
            // to exactly that on MySQL (ON CONFLICT DO UPDATE on the
            // SQLite test connection), keyed on setting_key's unique index.
            DB::table('system_settings')->upsert(
                ['setting_key' => $key, 'setting_value' => $val],
                ['setting_key'],
                ['setting_value'],
            );
        }

        AuditLogger::log(
            $actor,
            'update_settings',
            'system_settings',
            null,
            'Updated system settings',
        );
    }
}

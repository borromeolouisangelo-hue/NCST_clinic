<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * User medical-form mutations - extracted verbatim from
 * App\Http\Controllers\User\MedicalFormController::index()
 * (Phase 4, Batch 2).
 *
 * The controller keeps HTTP concerns: the $uid resolution, the latest
 * record / allergies reads, the form_action dispatch, the field reads
 * (including the school_year/semester key-existence + get_setting()
 * fallbacks), the failed-submit $rec overlay (view state), the
 * ?action=delete_allergy query read, the redirects and view rendering.
 * This service owns the three mutations:
 *
 *  - record(): the exact four rules/messages/append order (school year,
 *    height range, weight range, semester whitelist), then on success the
 *    INSERT ... ON DUPLICATE KEY UPDATE upsert on the uq_medrec_sem unique
 *    key (query-builder upsert: exactly that on MySQL, ON CONFLICT DO
 *    UPDATE on the SQLite test connection; NOW() is the submitted_at=
 *    update), the id re-read for the audit and the audit row itself.
 *
 *  - addAllergy(): the single name rule, then insert + audit (severity
 *    whitelist already normalized by the controller).
 *
 *  - deleteAllergy(): the OWNED delete (id AND user_id) + its audit row -
 *    which fires even when no row matched (original quirk); the
 *    controller keeps the redirect.
 *
 * Error strings, SQL text, error order and call order are byte-identical
 * to the controller's originals; $uid is $request->user()->id resolved
 * once by the controller.
 */
class MedicalFormService
{
    /**
     * Run the record pipeline: validations -> upsert -> audit.
     *
     * @return array{success: bool, errors: array<int, string>}
     */
    public function record(
        int $uid,
        string $year,
        string $semester,
        string $blood,
        ?float $height,
        ?float $weight,
        string $allergiesText,
        string $conditions,
        string $medications,
        string $immunizations,
        string $symptoms,
    ): array {
        $errors = [];

        // Exact rules, messages and append order (original lines 41-44).
        if ($year === '') {
            $errors[] = 'School year is required.';
        }
        if ($height !== null && ($height <= 0 || $height > 300)) {
            $errors[] = 'Height must be between 1 and 300 cm.';
        }
        if ($weight !== null && ($weight <= 0 || $weight > 700)) {
            $errors[] = 'Weight must be between 1 and 700 kg.';
        }
        if (! in_array($semester, ['1st', '2nd', 'summer'], true)) {
            $errors[] = 'Select a valid semester.';
        }

        if ($errors === []) {
            // Original lines 55-62: INSERT ... ON DUPLICATE KEY UPDATE
            // on the uq_medrec_sem unique key. The query builder's
            // upsert compiles to exactly that on MySQL (and to
            // ON CONFLICT DO UPDATE on the SQLite test connection);
            // NOW() is the submitted_at=NOW() update (shimmed for
            // SQLite in tests/TestCase.php).
            DB::table('medical_records')->upsert(
                [[
                    'user_id' => $uid,
                    'school_year' => $year,
                    'semester' => $semester,
                    'blood_type' => $blood,
                    'height_cm' => $height,
                    'weight_kg' => $weight,
                    'allergies_text' => $allergiesText,
                    'existing_conditions' => $conditions,
                    'current_medications' => $medications,
                    'immunizations' => $immunizations,
                    'current_symptoms' => $symptoms,
                    'submitted_at' => DB::raw('NOW()'),
                ]],
                ['user_id', 'school_year', 'semester'],
                [
                    'blood_type', 'height_cm', 'weight_kg', 'allergies_text',
                    'existing_conditions', 'current_medications', 'immunizations',
                    'current_symptoms', 'submitted_at',
                ]
            );

            $recordId = (int) DB::table('medical_records')
                ->where('user_id', $uid)
                ->where('school_year', $year)
                ->where('semester', $semester)
                ->value('id');

            AuditLogger::log($uid, 'submit_medical_form', 'medical_records', $recordId, 'Submitted medical form');

            return ['success' => true, 'errors' => []];
        }

        return ['success' => false, 'errors' => $errors];
    }

    /**
     * Run the add-allergy pipeline: name rule -> insert -> audit.
     *
     * @return array{success: bool, errors: array<int, string>}
     */
    public function addAllergy(int $uid, string $name, string $severity, string $notes): array
    {
        if ($name === '') {
            return ['success' => false, 'errors' => ['Allergen name is required.']];
        }

        $allergyId = (int) DB::table('user_allergies')->insertGetId([
            'user_id' => $uid,
            'allergen_name' => $name,
            'severity' => $severity,
            'notes' => $notes,
        ]);

        AuditLogger::log($uid, 'add_allergy', 'user_allergies', $allergyId, "Added allergy: {$name}");

        return ['success' => true, 'errors' => []];
    }

    /**
     * Delete an OWNED allergy + its audit row (the audit fires even when
     * no row matched - original quirk).
     */
    public function deleteAllergy(int $uid, int $allergyId): void
    {
        DB::delete('DELETE FROM user_allergies WHERE id=? AND user_id=?', [$allergyId, $uid]);

        AuditLogger::log($uid, 'delete_allergy', 'user_allergies', $allergyId, "Deleted allergy #{$allergyId}");
    }
}

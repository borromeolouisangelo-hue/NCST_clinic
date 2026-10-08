<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Consultation mutations - extracted verbatim from
 * App\Http\Controllers\ConsultationController::index() (Phase 4, Batch 2).
 *
 * The controller keeps HTTP concerns: the $numeric() request reader (blank
 * -> null, missing key -> 0/0.0), the ?edit= fetch, the ?action=delete
 * query read, the patients dropdown, the StaffHistory pagination, the
 * redirects and view rendering. This service owns the two mutations:
 *
 *  - save(): the two validations ("Please select a patient." /
 *    "Complaint is required."), then on success the insert (staff_id =
 *    injected $actor), the audit row, the complaint-shortening and the
 *    feature-2 Notify to the patient - fire_event('consultation.created')
 *    had no listener in the reference, feature 2 routes it straight to the
 *    patient (deduped on the consultation id).
 *
 *  - delete(): the plain-GET delete + its audit row (any staff member may
 *    delete any consultation - no ownership check, exactly like the
 *    original); the controller keeps the redirect.
 *
 * Error strings, SQL text, Notify payload and call order are byte-identical
 * to the controller's originals; only $request->user()->id became the
 * injected $actor (same value, resolved once by the controller).
 */
class ConsultationService
{
    /**
     * Run the save pipeline: validations -> insert -> audit -> Notify.
     *
     * @param  int  $pid  patient user id
     * @param  string  $compl  trimmed complaint
     * @param  string  $bp  trimmed blood pressure
     * @param  int|float|null  $temp  temperature (blank -> null, missing -> 0.0)
     * @param  int|null  $pulse  pulse
     * @param  int|null  $resp  respiration
     * @param  int|float|null  $wt  weight_kg
     * @param  int|float|null  $ht  height_cm
     * @param  string  $diag  trimmed diagnosis
     * @param  string  $treat  trimmed treatment
     * @param  string  $notes  trimmed notes
     * @param  int  $actor  authenticated staff id ($request->user()->id)
     * @return array{success: bool, errors: array<int, string>}
     */
    public function save(
        int $pid,
        string $compl,
        string $bp,
        int|float|null $temp,
        ?int $pulse,
        ?int $resp,
        int|float|null $wt,
        int|float|null $ht,
        string $diag,
        string $treat,
        string $notes,
        int $actor,
    ): array {
        $errors = [];

        if ($pid <= 0) {
            $errors[] = 'Please select a patient.';
        }
        if ($compl === '') {
            $errors[] = 'Complaint is required.';
        }

        if (! $errors) {
            $cid = DB::table('consultations')->insertGetId([
                'patient_id' => $pid,
                'staff_id' => $actor,
                'complaint' => $compl,
                'blood_pressure' => $bp,
                'temperature' => $temp,
                'pulse' => $pulse,
                'respiration' => $resp,
                'weight_kg' => $wt,
                'height_cm' => $ht,
                'diagnosis' => $diag,
                'treatment' => $treat,
                'notes' => $notes,
            ]);

            AuditLogger::log(
                $actor,
                'create_consultation',
                'consultations',
                $cid,
                "Consultation for patient #$pid",
            );

            // fire_event('consultation.created', ...) had no listener in the
            // reference; feature 2 routes the same event straight to the
            // patient (deduped on the consultation id).
            $complaintShort = mb_substr($compl, 0, 120);
            if (mb_strlen($compl) > 120) {
                $complaintShort .= '...';
            }
            Notify::to($pid, 'consultation_new', [
                'reference_id' => $cid,
                'severity' => 'info',
                'title' => 'Consultation Recorded',
                'message' => "A consultation was recorded for you: {$complaintShort}.",
                'action_url' => 'user/my_consultations',
            ]);

            return ['success' => true, 'errors' => []];
        }

        return ['success' => false, 'errors' => $errors];
    }

    /**
     * Delete a consultation + its audit row (no ownership check, original).
     */
    public function delete(int $cid, int $actor): void
    {
        DB::table('consultations')->where('id', $cid)->delete();

        AuditLogger::log(
            $actor,
            'delete_consultation',
            'consultations',
            $cid,
            "Deleted consultation #$cid",
        );
    }
}

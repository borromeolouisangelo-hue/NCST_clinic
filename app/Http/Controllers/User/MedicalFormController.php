<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\MedicalFormService;
use App\Support\ShellContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Phase 21 - user/medical_form.php (semester medical form + allergy registry).
 *
 * Behind require_login(['user']) (students + employees). Three handlers on
 * one URL like the reference: form_action=record upserts the semester's
 * medical_records row (unique user_id+school_year+semester),
 * form_action=add_allergy inserts into user_allergies, and
 * GET ?action=delete_allergy&id=N deletes an OWNED allergy (a GET state
 * change - the reference's data-confirm/data-swal-delete JS guard only).
 *
 * Legacy quirks kept on purpose: errors render TWICE (the top-level block
 * AND the allergy-modal block, both unconditional) and reopen the ALLERGY
 * modal for ANY failed submit (even the record form's); a failed record
 * submit re-shows the typed health fields but the STORED school
 * year/semester pair; the delete handler logs + flashes even when no row
 * matched; the modal's allergen input is duplicated in the markup.
 *
 * Phase 4 (Batch 2): the three mutations (record upsert, add-allergy,
 * delete-allergy) moved into App\Services\MedicalFormService, injected
 * below - this method keeps the reads, field resolution, the failed-submit
 * $rec overlay, dispatch and rendering.
 */
class MedicalFormController extends Controller
{
    public function __construct(protected MedicalFormService $medicalFormService) {}

    public function index(Request $request): View|RedirectResponse
    {
        $uid = (int) $request->user()->id;

        // Original lines 13-20: latest medical record + ordered allergies.
        $rec = (array) (DB::selectOne(
            'SELECT * FROM medical_records WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 1',
            [$uid]
        ) ?? []);
        $allergies = array_map(
            static fn ($row) => (array) $row,
            DB::select('SELECT * FROM user_allergies WHERE user_id = ? ORDER BY id', [$uid])
        );

        $errors = [];

        if ($request->isMethod('post')) {
            // Original lines 23-26 hard-redirected an invalid CSRF (header +
            // exit). The port's global VerifyCsrfToken middleware answers the
            // same token-less POST with 419 - approved Phase 6 strengthening.
            $formAction = (string) $request->input('form_action', 'record');

            if ($formAction === 'record') {
                $blood = trim((string) $request->input('blood_type', ''));

                // Original lines 31-32: '' (or absent) -> null, anything else
                // float-cast - so garbage becomes 0.0 and trips the range
                // check, exactly like the reference.
                $heightRaw = (string) ($request->input('height_cm') ?? '');
                $height = $heightRaw !== '' ? (float) $heightRaw : null;
                $weightRaw = (string) ($request->input('weight_kg') ?? '');
                $weight = $weightRaw !== '' ? (float) $weightRaw : null;

                $allergiesText = trim((string) $request->input('allergies_text', ''));
                $conditions = trim((string) $request->input('existing_conditions', ''));
                $medications = trim((string) $request->input('current_medications', ''));
                $immunizations = trim((string) $request->input('immunizations', ''));
                $symptoms = trim((string) $request->input('current_symptoms', ''));

                // Original lines 38-39: $_POST['x'] ?? get_setting(...) - a
                // POSTed empty string is an ERROR, only an ABSENT key falls
                // back to the setting. ConvertEmptyStringsToNull maps '' to
                // null, so key existence is read from the raw request bag.
                $posted = $request->request->all();
                $year = array_key_exists('school_year', $posted)
                    ? trim((string) $request->input('school_year'))
                    : trim((string) get_setting('current_school_year', date('Y')));
                $semester = array_key_exists('semester', $posted)
                    ? trim((string) $request->input('semester'))
                    : trim((string) get_setting('current_semester', '1st'));

                // Rules/messages, upsert, id re-read and audit live in
                // App\Services\MedicalFormService (Phase 4, Batch 2).
                $result = $this->medicalFormService->record(
                    $uid,
                    $year,
                    $semester,
                    $blood,
                    $height,
                    $weight,
                    $allergiesText,
                    $conditions,
                    $medications,
                    $immunizations,
                    $symptoms,
                );

                if ($result['success']) {
                    return redirect()
                        ->route('user.medical_form')
                        ->with('success', 'Medical form saved.');
                }

                $errors = $result['errors'];

                // Original lines 48-52: submitted health fields overlay
                // $rec so the re-render shows what was typed (school
                // year/semester are NOT overlaid - see index() below).
                $rec = array_merge($rec, [
                    'blood_type' => $blood,
                    'height_cm' => $height,
                    'weight_kg' => $weight,
                    'allergies_text' => $allergiesText,
                    'existing_conditions' => $conditions,
                    'current_medications' => $medications,
                    'immunizations' => $immunizations,
                    'current_symptoms' => $symptoms,
                ]);
            }

            if ($formAction === 'add_allergy') {
                $name = trim((string) $request->input('allergen_name', ''));
                $severityInput = (string) $request->input('severity', '');
                $severity = in_array($severityInput, ['mild', 'moderate', 'severe'], true)
                    ? $severityInput
                    : 'mild';
                $notes = trim((string) $request->input('notes', ''));

                // Rule, insert and audit live in
                // App\Services\MedicalFormService (Phase 4, Batch 2).
                $result = $this->medicalFormService->addAllergy($uid, $name, $severity, $notes);

                if ($result['success']) {
                    return redirect()
                        ->route('user.medical_form')
                        ->with('success', 'Allergy added.');
                }

                $errors = $result['errors'];
            }
        }

        // Original lines 88-93: GET-based delete, evaluated AFTER the POST
        // block (a POST carrying the query string would hit it too - kept).
        // The DELETE is scoped to the caller's own row, but the audit log and
        // flash fire even when nothing matched (original quirk).
        if ($request->query('action') === 'delete_allergy'
            && ($allergyId = (int) $request->query('id', 0)) > 0) {
            // Owned delete + audit moved into
            // App\Services\MedicalFormService (Phase 4, Batch 2).
            $this->medicalFormService->deleteAllergy($uid, $allergyId);

            return redirect()
                ->route('user.medical_form')
                ->with('success', 'Allergy removed.');
        }

        // Original lines 95-96: the form's year/semester always come from the
        // LATEST record (or the settings) - a failed submit therefore
        // re-shows the stored pair, never the rejected input.
        $year = $rec['school_year'] ?? get_setting('current_school_year', date('Y'));
        $sem = $rec['semester'] ?? get_setting('current_semester', '1st');

        return view('user.medical_form', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'user/medical_form'),
            'title' => 'Medical Form',
            'rec' => $rec,
            'allergies' => $allergies,
            'year' => $year,
            'sem' => $sem,
            'medicalErrors' => $errors,
        ]);
    }
}

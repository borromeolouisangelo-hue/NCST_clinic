<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Walk-in dispensing decision tree - extracted verbatim from
 * App\Http\Controllers\Staff\DispenseController::index() (Phase 3, part 1).
 *
 * The controller keeps every HTTP concern: reading the request, the
 * five-rule validation order (patient, medicine, the 1-unit rule, the
 * per-product reasonable limit, override required) and the redirect/flash
 * contract. This service owns what happens next, in exactly the order the
 * legacy staff/dispense.php port ran it:
 *
 *  1. the allergy conflict -> decision screen branch (reject maps to the
 *     'rejected' enum member; the UI labels it "Declined"),
 *  2. the 24-hour duplicate request guard,
 *  3. the stock check,
 *  4. the medicine_requests + deduct_stock_trace + dispense_logs
 *     DB::transaction (all-or-nothing; deduct_stock_trace()'s insufficient
 *     stock null rolls the whole thing back),
 *  5. the audit row and the patient-facing Notify alert.
 *
 * Every error string is byte-identical to the controller's originals and the
 * catch (\Throwable) behaviour is preserved: any failure - including one
 * thrown after the commit, by AuditLogger or Notify - is surfaced verbatim as
 * a form error and the page re-renders instead of redirecting. That means
 * rows can be written while an error is displayed; that quirk is inherited
 * unchanged from the controller.
 *
 * Only $request->user()->id was replaced: the controller resolves the
 * authenticated staff id once and passes it in as $staffId, so the service
 * never touches HTTP.
 */
class DispenseService
{
    /**
     * Run the POST decision tree for one walk-in dispense attempt.
     *
     * @param  int  $selPatient  selected patient (user_id), 0 when none
     * @param  int  $selMedicine  selected medicine (medicine_id), 0 when none
     * @param  int  $qty  raw posted quantity, already cast by the controller
     * @param  string  $override  trimmed override_reason ('' when absent)
     * @param  string  $notes  trimmed notes ('' when absent)
     * @param  string|array  $allergyDecision  raw allergy_decision post value
     *                                         ('', 'accept' or 'reject'; any
     *                                         other posted shape is compared
     *                                         with the same strict operators
     *                                         the controller used)
     * @param  int  $staffId  authenticated staff user id ($request->user()->id)
     * @param  array<int, string>  $errors  validation errors already collected
     *                                      by the controller (five-rule order).
     *                                      Only appended to, never reordered:
     *                                      the guards below short-circuit on
     *                                      it exactly like the original code.
     * @return array{success: bool, outcomeLabel: string|null, errors: array<int, string>, conflicts: array<int, array<string, mixed>>, showAllergyDecision: bool}
     */
    public function handle(
        int $selPatient,
        int $selMedicine,
        int $qty,
        string $override,
        string $notes,
        string|array $allergyDecision,
        int $staffId,
        array $errors,
    ): array {
        $conflicts = [];
        $showAllergyDecision = false;
        $success = false;
        $outcomeLabel = null;
        $reason = $notes !== '' ? $notes : 'Walk-in dispense';
        $outcome = 'dispensed';

        if (! $errors) {
            $conflicts = check_allergy_conflict($selPatient, $selMedicine);
            if ($outcome === 'dispensed' && $conflicts && $allergyDecision === '') {
                $showAllergyDecision = true;
            } elseif ($allergyDecision === 'reject') {
                // Stored value must be the enum member 'rejected'; the UI labels it "Declined".
                $outcome = 'rejected';
            }
        }

        if (! $errors && recent_medicine_request_conflict($selPatient, $selMedicine, $reason)) {
            $errors[] = 'This patient already has the same medicine or purpose recorded within the last 24 hours.';
        }

        if (! $errors && ! $showAllergyDecision) {
            $stock = medicine_stock($selMedicine);
            if ($outcome === 'dispensed' && $stock < $qty) {
                $errors[] = "Insufficient stock ($stock available).";
            } else {
                try {
                    $requestId = DB::transaction(function () use ($staffId, $selPatient, $selMedicine, $qty, $reason, $outcome, $conflicts, $override, $notes) {
                        $requestId = (int) DB::table('medicine_requests')->insertGetId([
                            'user_id' => $selPatient,
                            'medicine_id' => $selMedicine,
                            'quantity' => $qty,
                            'reason' => $reason,
                            'status' => $outcome,
                            'allergy_flag' => $conflicts ? 1 : 0,
                            'override_reason' => $override ?: null,
                            'processed_by' => $staffId,
                            'processed_at' => DB::raw('NOW()'),
                        ]);

                        if ($outcome === 'dispensed') {
                            $used = deduct_stock_trace(
                                $selMedicine,
                                $qty,
                                $staffId,
                                $requestId,
                                'Walk-in dispense: '.$reason,
                            );
                            if ($used === null) {
                                throw new \RuntimeException('Stock deduction failed.');
                            }
                            DB::table('dispense_logs')->insert([
                                'request_id' => $requestId,
                                'user_id' => $selPatient,
                                'medicine_id' => $selMedicine,
                                'batch_id' => $used[0]['batch_id'] ?? null,
                                'quantity' => $qty,
                                'dispensed_by' => $staffId,
                                'remarks' => ($conflicts ? '[ALLERGY OVERRIDE: '.$override.'] ' : '').$notes,
                            ]);
                        }

                        return $requestId;
                    });

                    $outcomeLabel = $outcome === 'dispensed' ? 'Dispensed' : 'Declined';

                    AuditLogger::log(
                        $staffId,
                        $outcome === 'dispensed' ? 'dispense_walkin' : 'reject_request',
                        'medicine_requests',
                        $requestId,
                        $outcomeLabel." walk-in request #$requestId for patient #$selPatient",
                    );

                    // Feature 2 (scoping): the alert goes to the patient the
                    // medicine was handed to - nobody else. Dedup on the
                    // request id, preference honours medicine_dispensed /
                    // medicine_declined.
                    $medRow = DB::selectOne(
                        'SELECT name, unit FROM medicines WHERE id = ? LIMIT 1',
                        [$selMedicine],
                    );
                    $medName = (string) ($medRow->name ?? 'medicine');
                    $medUnit = (string) ($medRow->unit ?? '');
                    $medQtyLabel = $medUnit !== '' ? "{$qty} {$medUnit}" : (string) $qty;

                    Notify::to($selPatient, $outcome === 'dispensed' ? 'medicine_dispensed' : 'medicine_declined', [
                        'reference_id' => $requestId,
                        'severity' => $outcome === 'dispensed' ? 'success' : 'warning',
                        'title' => $outcome === 'dispensed' ? 'Medicine Dispensed' : 'Medicine Request Declined',
                        'message' => $outcome === 'dispensed'
                            ? "Your walk-in request for {$medName} ({$medQtyLabel}) was dispensed by the clinic."
                            : "Your walk-in request for {$medName} was declined by the clinic. Please see the clinic staff for alternatives.",
                        'action_url' => 'user/my_requests',
                    ]);

                    // The original returned the redirect from here, so the
                    // conflict refresh below never ran on success - the
                    // ! $success guard keeps that query sequence intact.
                    $success = true;
                } catch (\Throwable $ex) {
                    $errors[] = $ex->getMessage();
                }
            }
        }

        // keep conflicts for display (original lines 102-105)
        if (! $success && $selPatient && $selMedicine) {
            $conflicts = check_allergy_conflict($selPatient, $selMedicine);
        }

        return [
            'success' => $success,
            'outcomeLabel' => $outcomeLabel,
            'errors' => $errors,
            'conflicts' => $conflicts,
            'showAllergyDecision' => $showAllergyDecision,
        ];
    }
}

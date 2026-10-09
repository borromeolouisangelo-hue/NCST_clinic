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
 * Tracking methods (replacing the old fill-level display): Individual items
 * deduct exact pieces through deduct_stock_trace, unchanged. Container +
 * Partial / Roll + Partial items never deduct whole units while dispensing -
 * "Dispense from: existing opened" records usage only, "open new" also runs
 * the opening event (opened count +1, no batch change) inside the same
 * transaction, and both are audit-logged separately from the dispense itself
 * (open_container vs dispense_walkin). Every inventory write still happens
 * only on the approved/dispensed path: rejections and the allergy decision
 * screen write nothing, as before.
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
     *                                      by the controller (five-rule order,
     *                                      plus the two tracking rules for
     *                                      partial items). Only appended to,
     *                                      never reordered: the guards below
     *                                      short-circuit on it exactly like
     *                                      the original code.
     * @param  string  $dispenseFrom  'existing' | 'new' | '' - container/roll
     *                                source; ignored (and normalized to '')
     *                                for Individual items. '' falls back to
     *                                'existing' when something is opened,
     *                                'new' otherwise (the posted default).
     * @param  string  $usage  'small' | 'moderate' | 'large' | '' - usage
     *                         record for the dispense_logs remark; never a
     *                         quantity, ignored for Individual items.
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
        string $dispenseFrom = '',
        string $usage = '',
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

            // One item lookup drives the tracking branch, the open_container
            // audit and the patient notify below (the notify's own late lookup
            // is gone - same query, same values, just earlier).
            $medRow = DB::selectOne(
                'SELECT name, unit FROM medicines WHERE id = ? LIMIT 1',
                [$selMedicine],
            );
            $medName = (string) ($medRow->name ?? 'medicine');
            $medUnit = (string) ($medRow->unit ?? '');
            $partial = StockUnits::partial($medUnit);
            // Usage is a record only - a label in the remark, never a quantity.
            $usageLabel = $partial ? match (strtolower($usage)) {
                'small' => 'Small',
                'moderate' => 'Moderate',
                'large' => 'Large',
                default => '',
            } : '';

            // Normalize the posted source for partial items once, here, so the
            // transaction and the open_container audit below see the same value.
            if ($partial && $dispenseFrom !== 'existing' && $dispenseFrom !== 'new') {
                $openedSource = StockUnits::openedCounts([$selMedicine])[$selMedicine] ?? 0;
                $dispenseFrom = $openedSource >= 1 ? 'existing' : 'new';
            }

            if ($outcome === 'dispensed' && $stock < $qty) {
                $errors[] = "Insufficient stock ($stock available).";
            } else {
                try {
                    $requestId = DB::transaction(function () use ($staffId, $selPatient, $selMedicine, $qty, $reason, $outcome, $conflicts, $override, $notes, $partial, $dispenseFrom, $usageLabel) {
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
                            $batchId = null;

                            if ($partial) {
                                // Container/Roll + Partial: dispensing never
                                // removes whole units. "Existing opened" is a
                                // usage record only; "open new" runs the
                                // opening event here - opened +1, no batch
                                // change - so opening, dispensing and
                                // depletion stay three separate events (the
                                // audit rows for open/deplete sit next to the
                                // dispense_walkin row this request also gets).
                                // $dispenseFrom was normalized above.
                                if ($dispenseFrom === 'new') {
                                    $openedNow = StockUnits::openedCounts([$selMedicine])[$selMedicine] ?? 0;
                                    if (! StockUnits::setOpened($selMedicine, $openedNow + 1)) {
                                        // Roll back: never record a dispense
                                        // whose opening could not be recorded.
                                        throw new \RuntimeException('Stock could not be updated for the new container.');
                                    }
                                }
                            } else {
                                // Individual: exact piece deduction, unchanged.
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
                                $batchId = $used[0]['batch_id'] ?? null;
                            }

                            DB::table('dispense_logs')->insert([
                                'request_id' => $requestId,
                                'user_id' => $selPatient,
                                'medicine_id' => $selMedicine,
                                'batch_id' => $batchId,
                                'quantity' => $qty,
                                'dispensed_by' => $staffId,
                                'remarks' => ($conflicts ? '[ALLERGY OVERRIDE: '.$override.'] ' : '')
                                    .($usageLabel !== '' ? '[USAGE: '.$usageLabel.'] ' : '')
                                    .$notes,
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

                    // Opening a new container/roll is its own inventory event
                    // - audited separately from the dispense above (depletion
                    // has its own action=deplete dispatch on the controller).
                    if ($outcome === 'dispensed' && $partial && $dispenseFrom === 'new') {
                        AuditLogger::log(
                            $staffId,
                            'open_container',
                            'medicines',
                            $selMedicine,
                            'Opened a new '.StockUnits::noun($medUnit).' of '.$medName.' for walk-in request #'.$requestId,
                        );
                    }

                    // Feature 2 (scoping): the alert goes to the patient the
                    // medicine was handed to - nobody else. Dedup on the
                    // request id, preference honours medicine_dispensed /
                    // medicine_declined. ($medRow/$medName/$medUnit were
                    // resolved once above, before the transaction.)
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

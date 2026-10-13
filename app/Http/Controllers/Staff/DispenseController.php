<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\DispenseService;
use App\Services\MedicineDailyLimit;
use App\Services\StaffHistory;
use App\Services\StockUnits;
use App\Support\ShellContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Walk-in dispensing page - port of staff/dispense.php (rule order, guards
 * and error strings stay byte-identical); the decision tree lives in
 * DispenseService, this class parses the request and renders.
 *
 * The old global duplicate guard (one request per patient per rolling 24
 * hours, matching the same purpose text across unrelated items) was replaced
 * by the per-medicine daily limit in MedicineDailyLimit: one request per
 * user per specific tablet/capsule medicine per calendar day. First-aid and
 * other non-tablet/capsule items are not subject to it.
 */
class DispenseController extends Controller
{
    // #region Wiring

    // Dispense mutations injected; this class only validates, parses and renders
    public function __construct(protected DispenseService $dispenseService) {}

    // #endregion

    // #region Dispense page

    // Run walk-in dispensing: deplete POST, validation (patient, medicine, the 1-unit rule, the per-product reasonable limit, the per-medicine daily limit, override required), then the dispense service and history render
    // <- POST (patient, medicine, qty, allergy decision, deplete), GET ?history_status/?q | -> view or redirect+flash | => stock deduction, audit, patient Notify (DispenseService)
    public function index(Request $request): View|RedirectResponse
    {
        $errors = [];
        $conflicts = [];
        $showAllergyDecision = false;
        $override = null;
        $selPatient = (int) ($request->post('user_id') ?? $request->query('user_id') ?? 0);
        $selMedicine = (int) ($request->post('medicine_id') ?? 0);

        if ($request->isMethod('post') && (string) $request->post('action', '') === 'deplete') {
            $deplete = StockUnits::depleteOpened($selMedicine, (int) $request->user()->id);

            if ($deplete['success']) {
                $noun = StockUnits::noun($deplete['unit']);
                AuditLogger::log(
                    (int) $request->user()->id,
                    'deplete_container',
                    'medicines',
                    $selMedicine,
                    'Marked opened '.$noun.' depleted (medicine #'.$selMedicine.')',
                );

                return redirect()
                    ->route('staff.dispense')
                    ->with('success', 'Opened '.$noun.' marked as depleted. Stock updated.');
            }

            return redirect()
                ->route('staff.dispense')
                ->with('danger', $deplete['error']);
        }

        if ($request->isMethod('post')) {
            $qty = (int) ($request->post('quantity') ?? 0);
            $override = trim($request->post('override_reason') ?? '');
            $notes = trim($request->post('notes') ?? '');
            $allergyDecision = $request->post('allergy_decision') ?? '';
            $reasonableLimit = get_setting_int('default_dispense_limit', 10);

            $dispenseFrom = trim((string) $request->post('dispense_from', ''));
            $usage = strtolower(trim((string) $request->post('usage', '')));
            if (! in_array($usage, ['small', 'moderate', 'large'], true)) {
                $usage = '';
            }

            $productUnit = null;
            if ($selMedicine > 0) {
                $productLimitRow = DB::selectOne(
                    'SELECT low_stock_threshold, unit FROM medicines WHERE id = ? AND is_active = 1 LIMIT 1',
                    [$selMedicine],
                );
                if ($productLimitRow) {
                    $reasonableLimit = max(1, (int) ($productLimitRow->low_stock_threshold ?? $reasonableLimit));
                    $productUnit = (string) ($productLimitRow->unit ?? '');
                }
            }

            if ($selPatient <= 0) {
                $errors[] = 'Please select a patient.';
            }
            if ($selMedicine <= 0) {
                $errors[] = 'Please select a medicine.';
            }
            if ($qty !== 1) {
                $errors[] = 'Each request is limited to 1 unit.';
            }
            if ($qty > $reasonableLimit) {
                $errors[] = 'Quantity exceeds the reasonable limit for this product (max '.(int) $reasonableLimit.').';
            }

            // Per-medicine daily limit: one request per user per specific
            // tablet/capsule medicine per calendar day. Eligibility comes
            // from the unit dosage-form column - first-aid and other
            // non-tablet/capsule items are never blocked by this rule.
            if ($selPatient > 0 && $selMedicine > 0 && $productUnit !== null
                && MedicineDailyLimit::appliesToUnit($productUnit)
                && MedicineDailyLimit::alreadyRequestedToday($selPatient, $selMedicine)) {
                $errors[] = 'You have already requested this medicine today. You may request it again tomorrow.';
            }

            if ($allergyDecision === 'accept' && $override === '') {
                $errors[] = 'An override reason is required when accepting an allergy conflict.';
            }

            if ($productUnit !== null && StockUnits::partial($productUnit)) {
                $openedCount = StockUnits::openedCounts([$selMedicine])[$selMedicine] ?? 0;
                if (! in_array($dispenseFrom, ['existing', 'new'], true)) {
                    $dispenseFrom = $openedCount >= 1 ? 'existing' : 'new';
                }

                $noun = StockUnits::noun($productUnit);
                if ($dispenseFrom === 'existing' && $openedCount < 1) {
                    $errors[] = 'There is no opened '.$noun.' yet. Choose "Open new '.$noun.'" instead.';
                }
                $stockOnHand = medicine_stock($selMedicine);
                if ($dispenseFrom === 'new' && $stockOnHand >= 1 && $stockOnHand - $openedCount < 1) {
                    $errors[] = 'No unopened '.$noun.' is left. Dispense from an existing opened '.$noun.' or add stock.';
                }
            } else {
                $dispenseFrom = '';
                $usage = '';
            }

            $dispenseResult = $this->dispenseService->handle(
                selPatient: $selPatient,
                selMedicine: $selMedicine,
                qty: $qty,
                override: $override,
                notes: $notes,
                allergyDecision: $allergyDecision,
                staffId: (int) $request->user()->id,
                errors: $errors,
                dispenseFrom: $dispenseFrom,
                usage: $usage,
            );

            $errors = $dispenseResult['errors'];
            $conflicts = $dispenseResult['conflicts'];
            $showAllergyDecision = $dispenseResult['showAllergyDecision'];

            if ($dispenseResult['success']) {
                return redirect()
                    ->route('staff.dispense')
                    ->with('success', $dispenseResult['outcomeLabel'].' and added to the dispensing record.');
            }
        }

        $patients = DB::select(
            "SELECT u.id, CONCAT(p.first_name,' ',p.last_name) AS full_name,
                    u.username, sd.student_no, ed.employee_no
             FROM users u
             LEFT JOIN profiles p ON p.user_id=u.id
             LEFT JOIN student_details sd ON sd.user_id=u.id
             LEFT JOIN employee_details ed ON ed.user_id=u.id
             WHERE u.role='user' ORDER BY p.first_name",
        );

        $medicines = DB::select('SELECT id, name, unit, generic_name FROM medicines WHERE is_active=1 ORDER BY name');
        $requestedTodayIds = MedicineDailyLimit::requestedMedicineIdsToday($selPatient);
        foreach ($medicines as $m) {
            $m->stock = medicine_stock((int) $m->id);
            $m->limit = max(1, (int) ($m->low_stock_threshold ?? 10));
            // Only eligible (tablet/capsule) items already requested by the
            // selected patient today are marked - everything else stays
            // requestable under its existing rules.
            $m->blockedToday = MedicineDailyLimit::appliesToUnit($m->unit)
                && in_array((int) $m->id, $requestedTodayIds, true);
        }

        $pieceStates = StockUnits::states($medicines);

        $search = trim((string) $request->query('q', ''));
        $historyStatus = $request->query('history_status') ?? 'all';
        if (! in_array($historyStatus, ['all', 'dispensed', 'rejected'], true)) {
            $historyStatus = 'all';
        }

        $filters = [
            'q' => $search,
            'history_status' => $historyStatus,
        ];

        // Module histories feature: 10 newest matching rows only - the
        // complete, paginated dispensing history lives behind the
        // toolbar's View All action (history() below). ?page= is no
        // longer read here.
        $result = StaffHistory::rows('dispense', $filters, 10, 0);
        $historyRows = $result['rows'];
        $historyError = $result['error'];

        return view('staff.dispense', [
            'title' => 'Dispense Item',
            'shell' => ShellContext::fromUser($request->user(), activePath: 'staff/dispense'),
            'errors' => $errors,
            'conflicts' => $conflicts,
            'showAllergyDecision' => $showAllergyDecision,
            'override' => $override,
            'selPatient' => $selPatient,
            'selMedicine' => $selMedicine,
            'patients' => $patients,
            'medicines' => $medicines,
            'pieceStates' => $pieceStates,
            'historyStatus' => $historyStatus,
            'historyRows' => $historyRows,
            'historyError' => $historyError,
        ]);
    }

    // #endregion
}

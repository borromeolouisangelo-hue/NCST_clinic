<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\DispenseService;
use App\Services\StaffHistory;
use App\Services\StockUnits;
use App\Support\ShellContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Staff walk-in dispensing - port of staff/dispense.php (read-only reference).
 *
 * require_login(['staff']) becomes ['auth', 'role:staff'] route middleware;
 * the custom CSRF failure branch (flash + redirect) is Laravel's 419
 * (approved Phase 6 difference). The whole decision tree runs exactly like
 * the reference: field collection and the five-rule validation order, the
 * allergy conflict -> decision screen, the 24h duplicate guard, the stock
 * check, and the medicine_requests + deduct_stock_trace + dispense_logs
 * transaction (all-or-nothing, exception message surfaced as a form error).
 * fire_event('medicine.dispensed'|'medicine.declined') still has no listener
 * in the reference dispatcher; feature 2 replaced that dead seam with a
 * direct App\Services\Notify() call addressed to the patient (scoping), so
 * the patient's medicine_dispensed / medicine_declined switch now does
 * something.
 * set_flash(...) becomes ->with(...) : written but never rendered
 * (write-only legacy slot, see partials/flash.blade.php).
 *
 * Legacy quirks kept: ?user_id= preselects the patient even on GET while
 * medicine_id is POST-only; qty must be exactly 1 (so a missing quantity
 * reports the 1-unit rule before the reasonable-limit rule); the history
 * filter maps any unknown status to 'all'; history is capped at 200 rows.
 *
 * The four deduction guards the spec calls for are the ones above, kept as
 * they are: the 1-unit rule blocks an invalid piece count, medicine_stock()
 * plus deduct_stock_trace()'s insufficient-stock null (which the caller
 * rolls back) block negative stock and over-deduction, and the 24h
 * duplicate guard blocks a double deduction.
 *
 * Post-migration addition (feature 1): $pieceStates feeds a read-only
 * indicator under the quantity field - "Pieces in the open package: 11/12"
 * for tablets/boxes, "Fill level: Full/Half Empty/Empty" for the rest - so
 * staff see the package state before they deduct. No request handling
 * changed.
 *
 * Phase 3 (part 1): the POST decision tree that ran after the five-rule
 * validation (allergy conflict gate, 24h duplicate guard, stock check,
 * medicine_requests + deduct_stock_trace + dispense_logs transaction, audit
 * + patient notify) now lives in App\Services\DispenseService, injected
 * below. This class keeps the HTTP concerns: reading the request, the five
 * rules and the redirect/view response. Behaviour, error strings and query
 * order are unchanged.
 */
class DispenseController extends Controller
{
    public function __construct(protected DispenseService $dispenseService) {}

    public function index(Request $request): View|RedirectResponse
    {
        $errors = [];
        $conflicts = [];
        $showAllergyDecision = false;
        $override = null;
        $selPatient = (int) ($request->post('user_id') ?? $request->query('user_id') ?? 0);
        $selMedicine = (int) ($request->post('medicine_id') ?? 0);

        if ($request->isMethod('post')) {
            $qty = (int) ($request->post('quantity') ?? 0);
            $override = trim($request->post('override_reason') ?? '');
            $notes = trim($request->post('notes') ?? '');
            $allergyDecision = $request->post('allergy_decision') ?? '';
            $reasonableLimit = get_setting_int('default_dispense_limit', 10);

            if ($selMedicine > 0) {
                $productLimitRow = DB::selectOne(
                    'SELECT low_stock_threshold FROM medicines WHERE id = ? AND is_active = 1 LIMIT 1',
                    [$selMedicine],
                );
                if ($productLimitRow) {
                    $reasonableLimit = max(1, (int) ($productLimitRow->low_stock_threshold ?? $reasonableLimit));
                }
            }

            if ($selPatient <= 0) {
                $errors[] = 'Please select a patient.';
            }
            if ($selMedicine <= 0) {
                $errors[] = 'Please select a medicine.';
            }
            if ($qty !== 1) {
                $errors[] = 'Medicine requests are limited to 1 unit per day.';
            }
            if ($qty > $reasonableLimit) {
                $errors[] = 'Quantity exceeds the reasonable limit for this product (max '.(int) $reasonableLimit.').';
            }

            if ($allergyDecision === 'accept' && $override === '') {
                $errors[] = 'An override reason is required when accepting an allergy conflict.';
            }

            // The domain decision tree - allergy conflict gate, 24h duplicate
            // guard, stock check, medicine_requests + deduct_stock_trace +
            // dispense_logs transaction, audit row and patient Notify - moved
            // verbatim into App\Services\DispenseService (Phase 3, part 1).
            // The errors collected above are passed in, so the five-rule order
            // and every guard stay exactly as they were; only HTTP (redirect,
            // flash, view) remains here.
            $dispenseResult = $this->dispenseService->handle(
                selPatient: $selPatient,
                selMedicine: $selMedicine,
                qty: $qty,
                override: $override,
                notes: $notes,
                allergyDecision: $allergyDecision,
                staffId: (int) $request->user()->id,
                errors: $errors,
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

        // The reference computes stock and the per-option limit inside the
        // option loop (staff/dispense.php:188). The list selects only
        // id/name/unit/generic_name, so `$m['low_stock_threshold'] ?? 10` in
        // the original always falls back to 10 and data-limit is 10 for every
        // option - the same keys are kept missing here to preserve that.
        $medicines = DB::select('SELECT id, name, unit, generic_name FROM medicines WHERE is_active=1 ORDER BY name');
        foreach ($medicines as $m) {
            $m->stock = medicine_stock((int) $m->id);
            $m->limit = max(1, (int) ($m->low_stock_threshold ?? 10));
        }

        // Post-migration feature: piece/package state per item so the form can
        // show "11/12 pieces in the open package" (tablet), "49/50" (box) or
        // the manual Full/Half Empty/Empty level for the non-piece units.
        // Two queries for the whole list - see App\Services\StockUnits.
        $medicineIds = array_map(fn ($m) => (int) $m->id, $medicines);
        $outTotals = StockUnits::outTotals($medicineIds);
        $stockLevels = StockUnits::levels($medicineIds);
        $pieceStates = [];
        foreach ($medicines as $m) {
            $id = (int) $m->id;
            $pieceStates[$id] = ['unit' => (string) $m->unit] + StockUnits::state(
                $id,
                $m->unit,
                (int) $m->stock,
                $outTotals[$id] ?? 0,
                $stockLevels[$id] ?? null,
            );
        }

        $search = trim((string) $request->query('q', ''));
        $historyStatus = $request->query('history_status') ?? 'all';
        if (! in_array($historyStatus, ['all', 'dispensed', 'rejected'], true)) {
            $historyStatus = 'all';
        }
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $filters = [
            'q' => $search,
            'status' => $historyStatus,
        ];

        $result = StaffHistory::rows('dispense', $filters, $perPage, $offset);
        $historyRows = $result['rows'];
        $totalHistory = $result['total'];
        $historyError = $result['error'];

        $totalPages = (int) ceil($totalHistory / $perPage);
        $pagination = [
            'current' => $page,
            'total' => max(1, $totalPages),
            'perPage' => $perPage,
            'totalItems' => $totalHistory,
            'baseUrl' => url('staff/dispense'),
            'query' => array_filter(['q' => $search, 'history_status' => $historyStatus]),
        ];

        return view('staff.dispense', [
            'title' => 'Requests',
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
            'totalHistory' => $totalHistory,
            'historyError' => $historyError,
            'pagination' => $pagination,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
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
 * Post-migration addition (feature 1, since replaced): $pieceStates feeds
 * the stock line under the quantity field - "Stock: 103 tablets" for
 * Individual items, "Stock: 8 unopened bottles · 1 opened bottle" for
 * Container + Partial / Roll + Partial items, which also get the
 * "Dispense from" (existing opened vs open new) and "Usage" (Small /
 * Moderate / Large, a record only) controls plus the Mark Depleted form.
 * Two POST additions sit in front of the five-rule validation: the
 * action=deplete dispatch (its own form - never needs patient/quantity
 * fields) and the two tracking rules for partial items. Everything else is
 * unchanged.
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

        // Mark Depleted - dispatched before the five-rule validation: the
        // deplete form posts only action + medicine_id, so it must never be
        // blocked by the patient/quantity rules. Opening (the "Open new
        // container/roll" radio on a successful dispense) and dispensing are
        // separate events; depletion is the third - one unit leaves stock
        // through an OUT movement and the opened count drops (StockUnits).
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

            // Container/Roll + Partial controls (ignored for Individual items).
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
                $errors[] = 'Medicine requests are limited to 1 unit per day.';
            }
            if ($qty > $reasonableLimit) {
                $errors[] = 'Quantity exceeds the reasonable limit for this product (max '.(int) $reasonableLimit.').';
            }

            if ($allergyDecision === 'accept' && $override === '') {
                $errors[] = 'An override reason is required when accepting an allergy conflict.';
            }

            // Tracking rules for Container + Partial / Roll + Partial items -
            // appended after the five rules above (never reordered). The
            // posted radio falls back to the sensible default when absent
            // (no JS / older form state), exactly like the rendering does.
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
                    // Every unit on hand is already opened - the plain
                    // "Insufficient stock" rule still owns the stock = 0 case.
                    $errors[] = 'No unopened '.$noun.' is left. Dispense from an existing opened '.$noun.' or add stock.';
                }
            } else {
                $dispenseFrom = '';
                $usage = '';
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

        // Tracking state per item (App\Services\StockUnits): one settings
        // query + one OUT query for the whole list. Individual items carry
        // the package position (cell: "11/12 deducted", modal label:
        // "Stock: 103 tablets"); Container + Partial / Roll + Partial items
        // carry the opened counts behind the modal's "Stock: 8 unopened
        // bottles · 1 opened bottle" line. The form shows the Dispense from
        // / Usage controls only for the partial methods.
        $pieceStates = StockUnits::states($medicines);

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
            'title' => 'Dispense',
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

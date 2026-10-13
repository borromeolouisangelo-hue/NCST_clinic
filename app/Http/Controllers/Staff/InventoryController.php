<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\EquipmentStockService;
use App\Services\InventoryRequestService;
use App\Services\StockService;
use App\Services\StockUnits;
use App\Support\ShellContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Staff inventory - port of staff/inventory.php (read-only reference).
 *
 * require_login(['staff']) becomes ['auth', 'role:staff'] route middleware;
 * the custom CSRF failure branch (flash + redirect) is Laravel's 419
 * (approved Phase 6 difference). Everything else runs like the reference:
 * the item-type CASE lookup that makes first aid items ignore the expiry
 * field, the four independent receive validations (min 3, expiry not in
 * past, date_received strtotime-able), the batch-merge query (COALESCE
 * identity match, newest first) that either UPDATEs quantity or INSERTs a
 * new batch inside a transaction, the transactionless delete branch (a
 * missing id falls through to the render with an error), and the GET
 * ?item_type= whitelist. In the reference the toolbar's ?q= and ?stock=
 * inputs are DEAD (echoed but never applied to any query) and ?item_type=
 * has no form field (URL-only); all three apply here (see below).
 *
 * The dead computation loop before the view (original 143-153) is kept
 * verbatim: its variables are recomputed inside the row blocks, but the
 * loop defines $today which the view uses. set_flash('success', ...) becomes
 * ->with() : written but never rendered (write-only legacy slot, see
 * partials/flash.blade.php).
 *
 * Three separate inventory tables (Medicines / First Aid / Wound Care /
 * Equipment): the page keeps ONE route and ONE Stock In modal, but the list
 * is three dedicated tables switched by navigation pills - the same
 * Bootstrap nav-pills + full-navigation-on-?tab= pattern the Transaction
 * History page uses (see staff/history.blade.php). ?tab= is the single
 * source of truth for which table is on screen, so each tab owns its own
 * ?q=, ?stock=, ?category=, ?page= and ?view= state; nothing bleeds between
 * them because only the active tab's query ever runs.
 *
 * The three tables stay separate BECAUSE the data model is: medicines and
 * first aid items are rows of the same `medicines` table split by
 * medicine_categories.type, batches live in medicine_batches, and equipment
 * has its own `equipment` table with its own total_qty / available_qty
 * counters that EquipmentLoanService moves on issue and return. Nothing was
 * copied into a parallel ledger.
 *
 *   - Medicines renders ONE ROW PER LIVE BATCH (plus one row for a medicine
 *     that has none), so Batch No., Lot No., Supplier, Unit Cost and
 *     Expiration Date are real per-row values and different batches are
 *     never merged. Stock Status stays MEDICINE-level (the threshold
 *     ladder) so low-stock alerts keep working exactly as before.
 *
 *   - First Aid / Wound Care renders ONE ROW PER PRODUCT with the batch
 *     columns folded away - it has no Expiration Date column or field at
 *     all, matching the StockService expiry bypass that already ignores the
 *     posted expiry for first aid items. Supplier / Unit Cost come from the
 *     product's most recently received batch.
 *
 *   - Equipment renders ONE ROW PER ITEM off the equipment table, showing
 *     the serial/asset identifier instead of a batch number. Equipment is
 *     quantity-tracked (total_qty / available_qty), so a missing asset id
 *     renders as a dash - it is never invented.
 *
 * View buttons are explicit <a> buttons, never clickable rows, and each one
 * carries ?tab=&view=&id= so the details modal always shows the record the
 * button belongs to: view=batch (one batch), view=item (a medicine with no
 * batches), view=product (a first aid product and its batches),
 * view=equipment (one equipment item).
 *
 * The isolated SQLite test fixtures create no equipment table (or, in one
 * case, no equipment stock-in columns), so the equipment reads stay behind
 * the try/catch that reads a missing table as "no equipment rows" - the
 * same defensive convention the old Equipment card used.
 *
 * APPROVAL WORKFLOW: this page creates nothing NEW any more. Creating a
 * medicine, first aid product, equipment item, category or supplier goes
 * through a pending `inventory_requests` row (action=request) that an admin
 * approves on /admin/inventory_requests, and the "-- add new equipment --"
 * option is gone from the Stock In modal - re-enforced server-side below.
 * Restocking an item that already exists needs NO approval: it runs the same
 * pipelines as before, only gaining a one-shot stock_receipts token so a
 * double submit cannot double-count, plus an informational notification to
 * every admin.
 */
class InventoryController extends Controller
{
    /** Rows per page for each tab's table. */
    public const PER_PAGE = 50;

    /**
     * The three inventory tabs, in display order. The key is the ?tab= value
     * and the label is the pill text.
     */
    public const TABS = [
        'medicines' => 'Medicines',
        'first_aid' => 'First Aid / Wound Care',
        'equipment' => 'Equipment',
        'requests' => 'My Inventory Requests',
    ];

    public function __construct(
        protected StockService $stockService,
        protected EquipmentStockService $equipmentStockService,
        protected InventoryRequestService $requestService,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $medicines = DB::select(
            'SELECT m.id, m.name, m.unit, c.name AS category_name, c.type
             FROM medicines m
             LEFT JOIN medicine_categories c ON c.id = m.category_id
             WHERE m.is_active = 1
             ORDER BY c.name, m.name'
        );
        $suppliers = DB::select('SELECT id, name FROM suppliers ORDER BY name');

        // The Stock In modal's Equipment item selector - the same equipment
        // rows the Equipment tab lists, so restocking an item that was
        // created here picks up the existing record instead of a duplicate.
        $equipmentItems = $this->equipmentItems();

        $errors = [];
        // Which modal a failed POST should reopen - 'request' submissions and
        // Stock In receipts are different forms on the same page.
        $openModal = null;

        // ---- Receive / delete (original lines 20-113) --------------------
        if ($request->isMethod('post')) {
            $action = $request->post('action', 'receive');
            $mid = (int) $request->post('medicine_id', 0);
            $batchNo = trim($request->post('batch_no') ?? '');
            $lotNo = trim($request->post('lot_no') ?? '');
            $supId = (int) ($request->post('supplier_id') ?? 0) ?: null;
            $qty = (int) $request->post('quantity', 0);
            $received = trim($request->post('date_received') ?? '') ?: date('Y-m-d');
            $cost = trim($request->post('unit_cost') ?? '') !== '' ? (float) $request->post('unit_cost') : null;

            // Which half of the Stock In modal posted: the Category dropdown
            // at the top of the form. Anything unrecognised falls back to the
            // medicine path, exactly as before.
            $receiveCategory = in_array($request->post('category', 'medicine'), ['medicine', 'first_aid', 'equipment'], true)
                ? $request->post('category')
                : 'medicine';

            // Item-type CASE lookup (batch expiry tracking) moved verbatim into
            // App\Services\StockService (Phase 3, Step 1). It still runs for
            // every POST with a selected item - query order unchanged - and the
            // posted expiry is still only read for non-first_aid items. A
            // medicine_id is never posted by the equipment half, so this
            // resolves to null without touching the database.
            $expiry = $this->stockService->resolveExpiry($mid, $request->post('expiry_date'));

            // One-shot idempotency token for the Stock In form. It is
            // regenerated on every render, so a double submit (or a browser
            // retry) of the SAME form is caught, while a genuinely new
            // receipt is always allowed. The receipt row is claimed inside
            // the receiving transaction, so the stock increase and its
            // once-only receipt cannot drift apart.
            $receiptToken = Str::uuid()->toString();

            if ($action === 'request') {
                // Creating something that does not exist yet - a new medicine
                // or first aid item, new equipment, a new category or a new
                // supplier - never writes inventory here. It lands as a
                // pending inventory_requests row for an admin to approve.
                $result = $this->requestService->submit((int) $request->user()->id, $request->post());

                if ($result['ok']) {
                    return redirect()
                        ->route('staff.inventory', ['tab' => $this->tabForRequestType((string) $request->post('request_type'))])
                        ->with('success', 'Request submitted. An admin will review it before the record is created.');
                }

                $errors = $result['errors'];
            } elseif ($action === 'receive') {
                if ($receiveCategory === 'equipment') {
                    $equipmentId = (int) $request->post('equipment_id', 0);

                    // New equipment is never created here any more: the
                    // "-- add new equipment --" path is gone from the modal
                    // and re-enforced server-side, because a new inventory
                    // record needs admin approval. Restocking one that
                    // already exists stays instant.
                    if ($equipmentId <= 0) {
                        $errors[] = 'New equipment is created once a request is approved. Submit a request from the Inventory page, then restock it here.';
                    } else {
                        // Equipment never had batches, lot numbers or expiry
                        // dates, so it keeps its own receive pipeline over the
                        // equipment counters - see EquipmentStockService.
                        $result = $this->equipmentStockService->receive(
                            equipmentId: $equipmentId,
                            newName: '',
                            newDescription: trim($request->post('equipment_notes') ?? ''),
                            qty: $qty,
                            supId: $supId,
                            unitCost: $cost,
                            received: $received,
                            condition: trim($request->post('condition') ?? ''),
                            assetId: trim($request->post('asset_id') ?? ''),
                            staffId: (int) $request->user()->id,
                            receiptToken: $receiptToken,
                        );

                        if ($result['success']) {
                            // Informational only: written after the stock
                            // increase committed, and it never throws - a
                            // notification failure cannot roll back or repeat
                            // the restock.
                            $this->notifyAdminsOfRestock((int) $result['receipt_id']);

                            return redirect()
                                ->route('staff.inventory', ['tab' => 'equipment'])
                                ->with('success', 'Stock added successfully.');
                        }

                        if ($result['duplicate']) {
                            return redirect()
                                ->route('staff.inventory', ['tab' => 'equipment'])
                                ->with('info', 'This stock receipt was already processed. No quantity was added twice.');
                        }

                        $errors = $result['errors'];
                    }
                } else {
                    // Receive pipeline - four validations, manual beginTransaction /
                    // commit / rollBack, batch merge, stock movement log and audit
                    // row - moved verbatim into App\Services\StockService (Phase 3,
                    // Step 1). Response formatting (redirect + flash) stays here.
                    $result = $this->stockService->receive(
                        mid: $mid,
                        batchNo: $batchNo,
                        lotNo: $lotNo,
                        supId: $supId,
                        qty: $qty,
                        expiry: $expiry,
                        received: $received,
                        cost: $cost,
                        staffId: (int) $request->user()->id,
                        receiptToken: $receiptToken,
                    );

                    if ($result['success']) {
                        $this->notifyAdminsOfRestock((int) $result['receipt_id']);

                        // Back to the tab the item actually belongs to. This
                        // reads the item's own category type rather than the
                        // posted Category dropdown, so the freshly stocked
                        // row is the one on screen even if a client posts a
                        // category that disagrees with the record.
                        return redirect()
                            ->route('staff.inventory', ['tab' => $this->tabForMedicine($mid)])
                            ->with('success', 'Stock added successfully.');
                    }

                    if ($result['duplicate']) {
                        return redirect()
                            ->route('staff.inventory', ['tab' => $this->tabForMedicine($mid)])
                            ->with('info', 'This stock receipt was already processed. No quantity was added twice.');
                    }

                    $errors = $result['errors'];
                }
            } elseif ($action === 'delete') {
                $bid = (int) $request->post('batch_id', 0);
                if ($bid > 0) {
                    DB::delete('DELETE FROM medicine_batches WHERE id = ?', [$bid]);

                    AuditLogger::log(
                        (int) $request->user()->id,
                        'delete_batch',
                        'medicine_batches',
                        $bid,
                        "Deleted batch #$bid",
                    );

                    return redirect()
                        ->route('staff.inventory')
                        ->with('success', 'Batch deleted.');
                }
                $errors[] = 'Batch could not be found.';
            }
            // The old third action, set_level (Full / Half Empty / Empty
            // buttons per row), was removed with the fill-level system. The
            // Total Pieces column now shows the tracking state instead
            // (Individual / Container + Partial / Roll + Partial) - see
            // App\Services\StockUnits.

            if ($errors !== []) {
                $openModal = $action === 'request' ? 'requestModal' : 'stockInModal';
            }
        }

        // ---- Tabs, filters and the active tab's table ---------------------
        // Read once into a local: query('tab') returns null when the key is
        // absent, so a second bare read would turn the default back into ''
        // and leave $tabs[$tab] with an empty-string key.
        $requestedTab = (string) $request->query('tab', 'medicines');
        $tab = array_key_exists($requestedTab, self::TABS) ? $requestedTab : 'medicines';
        $q = trim((string) $request->query('q', ''));
        $stockFilter = in_array($request->query('stock', ''), ['in', 'low', 'out'], true)
            ? (string) $request->query('stock')
            : '';
        $today = date('Y-m-d');

        // Roots (parent_id NULL) sort first, then their children - portable
        // across MySQL and SQLite, which both order NULLs first on ASC.
        $categories = DB::select(
            'SELECT id, parent_id, name, type FROM medicine_categories ORDER BY type, parent_id, id',
        );
        $parentOf = [];
        $typeOf = [];
        $tabCategories = [];
        foreach ($categories as $cat) {
            $id = (int) $cat->id;
            $parentOf[$id] = $cat->parent_id !== null ? (int) $cat->parent_id : 0;
            $typeOf[$id] = (string) $cat->type;
            // Each tab's Category filter only offers its own type's tree, so
            // a medicine category can never be picked from the First Aid tab.
            if ($tab !== 'equipment' && $tab !== 'requests' && (string) $cat->type === $tab) {
                $tabCategories[] = $cat;
            }
        }
        $filterCategory = (int) $request->query('category', 0);
        if (! array_key_exists($filterCategory, $parentOf)) {
            $filterCategory = 0;
        }
        // A category belonging to the other tab's type is not a filter for
        // this one, so it collapses to no category.
        if ($tab !== 'equipment' && $filterCategory !== 0 && ($typeOf[$filterCategory] ?? '') !== $tab) {
            $filterCategory = 0;
        }

        // Picking a root category ("Medicines", "First Aid / Wound Care")
        // matches its whole subtree: items always hang off the leaf rows, so
        // filtering on the root id alone would always return nothing.
        $categoryFilterIds = [];
        if ($filterCategory !== 0) {
            $queue = [$filterCategory];
            $seen = [];
            while ($queue !== []) {
                $id = (int) array_shift($queue);
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $categoryFilterIds[] = $id;
                foreach ($parentOf as $child => $parent) {
                    if ($parent === $id) {
                        $queue[] = $child;
                    }
                }
            }
        }

        $rows = [];
        $stockStates = [];

        if ($tab === 'equipment') {
            $rows = $this->equipmentRows($q, $stockFilter);
        } elseif ($tab === 'requests') {
            // Requests tab - rows will be empty; the My Requests card
            // below the table will be shown instead.
        } else {
            // Medicines and First Aid share one medicines query - only the
            // CASE item-type value differs, which is what splits the two
            // tabs in the first place.
            $medicineRows = $this->medicineRows($tab, $q, $filterCategory, $categoryFilterIds);

            if ($tab === 'medicines') {
                // One row per live batch, FEFO ordered, so no two batches are
                // ever merged; a medicine without batches still gets a row.
                $batches = $this->batchRows(array_column($medicineRows, 'medicine_id'));
                foreach ($medicineRows as $row) {
                    $rowBatches = $batches[$row->medicine_id] ?? [];
                    if ($rowBatches === []) {
                        $rows[] = $this->batchRow($row, null);

                        continue;
                    }
                    foreach ($rowBatches as $batch) {
                        $rows[] = $this->batchRow($row, $batch);
                    }
                }
                $stockStates = StockUnits::states($medicineRows);
            } else {
                // First aid: one row per product, supplier and unit cost from
                // the most recently received batch (the batch list is ordered
                // newest first).
                $batches = $this->batchRows(array_column($medicineRows, 'medicine_id'));
                foreach ($medicineRows as $row) {
                    $latest = $batches[$row->medicine_id][0] ?? null;
                    $rows[] = $this->productRow($row, $latest);
                }
                $stockStates = StockUnits::states($medicineRows);
            }

            // ?stock= keeps one of three disjoint buckets over the MEDICINE's
            // aggregated quantity (not the batch's), so a low-stock alert is
            // never hidden just because one batch is larger than another.
            if ($stockFilter !== '') {
                $rows = array_values(array_filter($rows, function ($row) use ($stockFilter) {
                    $total = (int) $row->medicine_total;
                    $threshold = max(1, (int) $row->low_stock_threshold);

                    return match ($stockFilter) {
                        'out' => $total <= 0,
                        'low' => $total > 0 && $total <= $threshold,
                        'in' => $total > $threshold,
                    };
                }));
            }
        }

        // Sorting: by name across the tab, then - on the medicines tab - by
        // nearest usable expiry so batches read in FEFO order.
        usort($rows, function ($a, $b) {
            $byName = strcasecmp($a->name, $b->name);
            if ($byName !== 0) {
                return $byName;
            }
            $ae = $a->expiry ?? '';
            $be = $b->expiry ?? '';
            if ($ae === $be) {
                return 0;
            }
            if ($ae === '') {
                return 1;
            }
            if ($be === '') {
                return -1;
            }

            return strcmp($ae, $be);
        });

        // Server-side pagination - identical per tab because only the active
        // tab's rows are ever in $rows.
        $totalItems = count($rows);
        $totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));
        $page = (int) $request->query('page', 1);
        if ($page < 1) {
            $page = 1;
        } elseif ($page > $totalPages) {
            $page = $totalPages;
        }
        $pageRows = array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        // Explicit View buttons (?tab=&view=&id=) - the details modal always
        // shows the record the button belonged to.
        $details = $this->details($request, $tab);

        return view('staff.inventory', [
            'title' => 'Inventory',
            'shell' => ShellContext::fromUser($request->user(), activePath: 'staff/inventory'),
            'errors' => $errors,
            'openModal' => $openModal,
            'medicines' => $medicines,
            'equipmentItems' => $equipmentItems,
            'suppliers' => $suppliers,
            'restockToken' => Str::uuid()->toString(),
            'requestCategories' => $categories,
            'requestTypes' => InventoryRequestService::TYPES,
            'requestTypeLabels' => InventoryRequestService::TYPE_LABELS,
            'conditionOptions' => EquipmentStockService::CONDITIONS,
            'myRequests' => $this->myRequests((int) $request->user()->id),
            'tabs' => self::TABS,
            'tab' => $tab,
            'q' => $q,
            'stock' => $stockFilter,
            'filterCategory' => $filterCategory,
            'categories' => $tabCategories,
            'rows' => $pageRows,
            'stockStates' => $stockStates,
            'details' => $details,
            'pagination' => [
                'current' => $page,
                'total' => $totalPages,
                'totalItems' => $totalItems,
                'perPage' => self::PER_PAGE,
                'baseUrl' => url('staff/inventory'),
                'query' => array_filter([
                    'tab' => $tab === 'medicines' ? null : $tab,
                    'q' => $q !== '' ? $q : null,
                    'stock' => $stockFilter !== '' ? $stockFilter : null,
                    'category' => $filterCategory ?: null,
                ], fn ($v) => $v !== null),
            ],
            'today' => $today,
        ]);
    }

    /**
     * Medicines / First Aid rows straight off the medicines table, already
     * carrying the tab's item type, the category name and the aggregated
     * quantity the threshold ladder and the stock buckets read.
     *
     * @param  array<int, int>  $categoryFilterIds
     * @return array<int, object>
     */
    private function medicineRows(string $tab, string $q, int $filterCategory, array $categoryFilterIds): array
    {
        // m.id is selected twice on purpose: StockUnits::states() keys each
        // row on ->id, while the row builders below read ->medicine_id.
        $sql = 'SELECT m.id, m.id AS medicine_id, m.name, m.unit, m.low_stock_threshold,
        c.name AS category_name,
        CASE WHEN c.type = "first_aid" THEN "first_aid" ELSE "medicine" END AS item_type,
        COALESCE(SUM(b.quantity), 0) AS total_qty,
        COUNT(b.id) AS batch_count
        FROM medicines m
        LEFT JOIN medicine_categories c ON c.id = m.category_id
        LEFT JOIN medicine_batches b ON b.medicine_id = m.id AND b.quantity > 0
        WHERE m.is_active = 1';
        $params = [];

        // The tab selects the item type - there is no separate item_type
        // filter. The TAB KEY is plural ("medicines") to match its pill
        // label, but the CASE below yields the singular type value, so the
        // two are mapped rather than compared directly.
        $sql .= ' AND CASE WHEN c.type = "first_aid" THEN "first_aid" ELSE "medicine" END = ?';
        $params[] = $tab === 'first_aid' ? 'first_aid' : 'medicine';

        if ($categoryFilterIds !== []) {
            $sql .= ' AND m.category_id IN ('.implode(',', array_fill(0, count($categoryFilterIds), '?')).')';
            foreach ($categoryFilterIds as $categoryId) {
                $params[] = $categoryId;
            }
        }
        if ($q !== '') {
            // Partial, case-insensitive (MySQL ci collation / SQLite's
            // ASCII-ci LIKE) match over the item and category names.
            $sql .= ' AND (m.name LIKE ? OR c.name LIKE ?)';
            $params[] = '%'.$q.'%';
            $params[] = '%'.$q.'%';
        }
        $sql .= ' GROUP BY m.id, m.name, m.unit, m.low_stock_threshold, c.name, c.type
        ORDER BY m.name ASC';

        try {
            return DB::select($sql, $params);
        } catch (\Throwable $ex) {
            return [];
        }
    }

    /**
     * Live batches for the given medicine ids, newest received first and
     * within that FEFO ordered, each joined to its supplier name.
     *
     * @param  array<int, int|string>  $medicineIds
     * @return array<int, array<int, object>>
     */
    private function batchRows(array $medicineIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $medicineIds)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));

        try {
            $batches = DB::select(
                "SELECT b.id AS batch_id, b.medicine_id, b.batch_no, b.lot_no, b.quantity,
                        b.expiry_date, b.date_received, b.unit_cost,
                        s.name AS supplier_name
                   FROM medicine_batches b
                   LEFT JOIN suppliers s ON s.id = b.supplier_id
                  WHERE b.quantity > 0 AND b.medicine_id IN ($in)
                  ORDER BY b.date_received DESC, b.id DESC",
                $ids,
            );
        } catch (\Throwable $ex) {
            return [];
        }

        $grouped = [];
        foreach ($batches as $batch) {
            $grouped[(int) $batch->medicine_id][] = $batch;
        }

        return $grouped;
    }

    /**
     * One Medicines-tab row: a live batch, or the medicine itself when it
     * has none (never a merged pseudo-batch).
     *
     * @param  object|null  $batch  a medicine_batches row, or null
     */
    private function batchRow(object $medicine, ?object $batch): object
    {
        [$label, $badge] = $this->medicineStatus(
            (int) $medicine->total_qty,
            (int) $medicine->low_stock_threshold,
        );

        return (object) [
            'kind' => $batch ? 'batch' : 'item',
            'viewId' => $batch ? (int) $batch->batch_id : (int) $medicine->medicine_id,
            'medicine_id' => (int) $medicine->medicine_id,
            'name' => (string) $medicine->name,
            'category' => $medicine->category_name,
            'batch_no' => $batch->batch_no ?? null,
            'lot_no' => $batch->lot_no ?? null,
            'supplier' => $batch->supplier_name ?? null,
            'unit_cost' => $batch->unit_cost ?? null,
            'expiry' => $batch->expiry_date ?? null,
            'qty' => $batch ? (int) $batch->quantity : (int) $medicine->total_qty,
            'unit' => (string) $medicine->unit,
            'medicine_total' => (int) $medicine->total_qty,
            'low_stock_threshold' => (int) $medicine->low_stock_threshold,
            'status_label' => $label,
            'status_badge' => $badge,
        ];
    }

    /**
     * One First Aid tab row: the whole product, no expiry column at all.
     *
     * @param  object|null  $latest  the product's most recently received batch
     */
    private function productRow(object $medicine, ?object $latest): object
    {
        [$label, $badge] = $this->medicineStatus(
            (int) $medicine->total_qty,
            (int) $medicine->low_stock_threshold,
        );

        return (object) [
            'kind' => 'product',
            'viewId' => (int) $medicine->medicine_id,
            'medicine_id' => (int) $medicine->medicine_id,
            'name' => (string) $medicine->name,
            'category' => $medicine->category_name,
            'supplier' => $latest->supplier_name ?? null,
            'unit_cost' => $latest->unit_cost ?? null,
            'qty' => (int) $medicine->total_qty,
            'unit' => (string) $medicine->unit,
            'medicine_total' => (int) $medicine->total_qty,
            'low_stock_threshold' => (int) $medicine->low_stock_threshold,
            'status_label' => $label,
            'status_badge' => $badge,
        ];
    }

    /**
     * Equipment-tab rows off the existing equipment table - the very
     * counters EquipmentLoanService moves on issue and return.
     *
     * ?stock= buckets equipment by AVAILABILITY (out = none available,
     * low = some units out on loan, in = everything available) because
     * equipment has no low_stock_threshold column.
     *
     * @return array<int, object>
     */
    private function equipmentRows(string $q, string $stockFilter): array
    {
        $sql = 'SELECT e.id AS equipment_id, e.name, e.description, e.total_qty, e.available_qty,
                e.condition_note, e.supplier_id, e.unit_cost, e.asset_id, e.last_received,
                e.is_active, s.name AS supplier_name
                 FROM equipment e
                 LEFT JOIN suppliers s ON s.id = e.supplier_id';
        $params = [];
        if ($q !== '') {
            // Partial, case-insensitive match over the name and description.
            $sql .= ' WHERE (e.name LIKE ? OR e.description LIKE ?)';
            $params[] = '%'.$q.'%';
            $params[] = '%'.$q.'%';
        }
        $sql .= ' ORDER BY e.name ASC';

        try {
            $equipment = DB::select($sql, $params);
        } catch (\Throwable $ex) {
            return [];
        }

        $rows = [];
        foreach ($equipment as $eq) {
            $total = (int) ($eq->total_qty ?? 0);
            $available = (int) ($eq->available_qty ?? 0);
            if ($stockFilter !== '') {
                $keep = match ($stockFilter) {
                    'out' => $available <= 0,
                    'low' => $available > 0 && $available < $total,
                    'in' => $available >= $total,
                };
                if (! $keep) {
                    continue;
                }
            }

            if (! $eq->is_active) {
                [$label, $badge] = ['Inactive', 'bg-secondary'];
            } elseif ($available <= 0) {
                [$label, $badge] = ['Unavailable', 'bg-danger'];
            } elseif ($available < $total) {
                [$label, $badge] = ['On loan', 'bg-warning'];
            } else {
                [$label, $badge] = ['Available', 'bg-success'];
            }

            $rows[] = (object) [
                'kind' => 'equipment',
                'viewId' => (int) $eq->equipment_id,
                'equipment_id' => (int) $eq->equipment_id,
                'name' => (string) $eq->name,
                // Equipment carries no category row, so its TYPE is the
                // category - no category value is invented for it.
                'type' => 'Equipment',
                // Serial number for individually tracked equipment; the
                // quantity-tracked items below simply have none.
                'serial' => $eq->asset_id,
                'condition' => $eq->condition_note,
                'supplier' => $eq->supplier_name,
                'unit_cost' => $eq->unit_cost,
                'qty' => $total,
                'available' => $available,
                'last_received' => $eq->last_received,
                'description' => $eq->description,
                'medicine_total' => 0,
                'low_stock_threshold' => 0,
                'status_label' => $label,
                'status_badge' => $badge,
            ];
        }

        return $rows;
    }

    /**
     * The threshold ladder every medicine / first aid row and ?stock= bucket
     * uses. low_stock_threshold is never written or removed here - only the
     * old visible Threshold COLUMN is gone.
     *
     * @return array{0: string, 1: string}
     */
    private function medicineStatus(int $qty, int $threshold): array
    {
        $threshold = max(1, $threshold);
        if ($qty <= 0) {
            return ['Out of stock', 'bg-secondary'];
        }
        if ($qty <= floor($threshold * 0.25)) {
            return ['Critical', 'bg-danger'];
        }
        if ($qty <= floor($threshold * 0.5)) {
            return ['Low', 'bg-warning'];
        }

        return ['Healthy', 'bg-success'];
    }

    /**
     * Which tab a medicine / first aid item lives on, read from the item's
     * own category type. Only used on the success path of a stock-in.
     */
    private function tabForMedicine(int $medicineId): string
    {
        $row = DB::selectOne(
            'SELECT CASE WHEN c.type = "first_aid" THEN "first_aid" ELSE "medicine" END AS item_type
               FROM medicines m
               LEFT JOIN medicine_categories c ON c.id = m.category_id
              WHERE m.id = ? LIMIT 1',
            [$medicineId],
        );

        return $row && $row->item_type === 'first_aid' ? 'first_aid' : 'medicines';
    }

    /**
     * Resolve the ?view=&id= details request into whatever the modal needs.
     * Every branch re-reads the record by id, so the modal can never show a
     * different record than the button that opened it.
     *
     * @return array{kind: string, data: array<string, mixed>}|null
     */
    private function details(Request $request, string $tab): ?array
    {
        $kind = (string) $request->query('view', '');
        $id = (int) $request->query('id', 0);
        if ($id <= 0) {
            return null;
        }

        if ($kind === 'batch') {
            $batch = DB::selectOne(
                'SELECT b.id AS batch_id, b.batch_no, b.lot_no, b.quantity, b.expiry_date,
                        b.date_received, b.unit_cost,
                        m.id AS medicine_id, m.name AS medicine_name, m.unit,
                        c.name AS category_name,
                        s.name AS supplier_name
                   FROM medicine_batches b
                   JOIN medicines m ON m.id = b.medicine_id
                   LEFT JOIN medicine_categories c ON c.id = m.category_id
                   LEFT JOIN suppliers s ON s.id = b.supplier_id
                  WHERE b.id = ? LIMIT 1',
                [$id],
            );

            return $batch ? ['kind' => 'batch', 'data' => $batch] : null;
        }

        if ($kind === 'item' || $kind === 'product') {
            $medicine = DB::selectOne(
                'SELECT m.id, m.name, m.generic_name, m.unit, m.description, m.low_stock_threshold,
                        m.is_active, c.name AS category_name
                   FROM medicines m
                   LEFT JOIN medicine_categories c ON c.id = m.category_id
                  WHERE m.id = ? LIMIT 1',
                [$id],
            );
            if (! $medicine) {
                return null;
            }

            $batches = [];
            try {
                // The first aid modal deliberately omits expiry_date from
                // its own table - see the view - so nothing is lost here.
                $batches = DB::select(
                    'SELECT b.id AS batch_id, b.batch_no, b.lot_no, b.quantity, b.expiry_date,
                            b.date_received, b.unit_cost, s.name AS supplier_name
                       FROM medicine_batches b
                       LEFT JOIN suppliers s ON s.id = b.supplier_id
                      WHERE b.medicine_id = ? AND b.quantity > 0
                      ORDER BY b.date_received DESC, b.id DESC',
                    [$id],
                );
            } catch (\Throwable $ex) {
                $batches = [];
            }

            $total = 0;
            foreach ($batches as $batch) {
                $total += (int) $batch->quantity;
            }

            // A plain array, not a cast object: the view reads this back as
            // $details['data']['medicine'] etc., and only the batch kind
            // below stays a raw DB row (accessed with ->).
            return ['kind' => $kind, 'data' => [
                'medicine' => $medicine,
                'batches' => $batches,
                'total' => $total,
            ]];
        }

        if ($kind === 'equipment') {
            try {
                $equipment = DB::selectOne(
                    'SELECT e.id, e.name, e.description, e.total_qty, e.available_qty,
                            e.condition_note, e.asset_id, e.unit_cost, e.last_received,
                            e.is_active, s.name AS supplier_name
                       FROM equipment e
                       LEFT JOIN suppliers s ON s.id = e.supplier_id
                      WHERE e.id = ? LIMIT 1',
                    [$id],
                );
            } catch (\Throwable $ex) {
                return null;
            }

            $loans = [];
            try {
                $loans = DB::select(
                    'SELECT l.id, l.status, l.borrow_date, l.return_date, l.quantity,
                            l.condition_on_return
                       FROM equipment_loans l
                      WHERE l.equipment_id = ?
                      ORDER BY l.borrowed_at DESC, l.id DESC',
                    [$id],
                );
            } catch (\Throwable $ex) {
                $loans = [];
            }

            if (! $equipment) {
                return null;
            }

            return ['kind' => 'equipment', 'data' => [
                'equipment' => $equipment,
                'loans' => $loans,
            ]];
        }

        return null;
    }

    /**
     * Active equipment for the Stock In modal's item selector.
     *
     * @return array<int, object>
     */
    private function equipmentItems(): array
    {
        try {
            return DB::select('SELECT id, name FROM equipment WHERE is_active = 1 ORDER BY name ASC');
        } catch (\Throwable $ex) {
            return [];
        }
    }

    /**
     * Which tab to land on after a request is submitted - read from the type
     * that was actually asked for, so the requester sees where their new item
     * will appear.
     */
    private function tabForRequestType(string $type): string
    {
        return $type === 'first_aid' ? 'first_aid' : 'medicines';
    }

    /**
     * The signed-in staff member's own requests, newest first, each with its
     * payload decoded for the My Requests table. Never throws - the requests
     * table predates this page in some fixtures.
     *
     * @return array<int, object>
     */
    private function myRequests(int $staffId): array
    {
        try {
            $rows = DB::select(
                'SELECT r.*, COALESCE(rv.username, "") AS reviewer_name
                   FROM inventory_requests r
              LEFT JOIN users rv ON rv.id = r.reviewed_by
                  WHERE r.requester_id = ?
                  ORDER BY r.created_at DESC, r.id DESC
                  LIMIT 100',
                [$staffId],
            );
        } catch (\Throwable $ex) {
            return [];
        }

        foreach ($rows as $row) {
            $row->data = json_decode((string) $row->payload, true) ?: [];
        }

        return $rows;
    }

    /**
     * Informational restock notice to every admin - posted AFTER the stock
     * increase has committed, and never allowed to fail the request.
     */
    private function notifyAdminsOfRestock(int $receiptId): void
    {
        if ($receiptId <= 0) {
            return;
        }

        try {
            $row = DB::table('stock_receipts')->where('id', $receiptId)->first();
            if (! $row) {
                return;
            }

            $itemName = '';
            if ((string) $row->item_type === 'equipment') {
                $itemName = (string) DB::table('equipment')->where('id', $row->item_id)->value('name');
            } else {
                $itemName = (string) DB::table('medicines')->where('id', $row->item_id)->value('name');
            }

            $supplierName = $row->supplier_id
                ? (string) DB::table('suppliers')->where('id', $row->supplier_id)->value('name')
                : '';

            $user = DB::table('users')->where('id', $row->staff_id)->first();
            $profile = DB::table('profiles')->where('user_id', $row->staff_id)->first();
            $full = trim(((string) ($profile->first_name ?? '')).' '.((string) ($profile->last_name ?? '')));

            $this->requestService->notifyRestock([
                'id' => (int) $row->id,
                'item_type' => (string) $row->item_type,
                'item_name' => $itemName,
                'quantity' => (int) $row->quantity,
                'supplier_name' => $supplierName,
                'staff_name' => $full !== '' ? $full : (string) ($user->username ?? ''),
                'created_at' => (string) $row->created_at,
            ]);
        } catch (\Throwable $ex) {
            // A notification must never fail the request that triggered it.
        }
    }
}

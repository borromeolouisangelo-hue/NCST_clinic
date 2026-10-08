<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\StockService;
use App\Services\StockUnits;
use App\Support\ShellContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
 * ?item_type= whitelist. The toolbar's ?q= and ?stock= inputs are DEAD in
 * the reference - echoed but never applied to any query - and stay dead
 * here; ?item_type= applies but has no form field (URL-only quirk).
 *
 * The dead computation loop before the view (original 143-153) is kept
 * verbatim: its variables are recomputed inside the row blocks, but the
 * loop defines $today which the view uses. set_flash('success', ...) becomes
 * ->with() : written but never rendered (write-only legacy slot, see
 * partials/flash.blade.php).
 *
 * Post-migration additions (features 1 and 4, additive - nothing above
 * changed): the ?item_type= whitelist gains a visible toolbar field and a
 * second ?category= filter over the existing medicine_categories tree (a
 * root category matches its whole subtree, since items always hang off the
 * leaf rows), and the POST dispatch gains a third action, set_level, which
 * stores the manual Full / Half Empty / Empty fill level for non-piece units
 * in the existing system_settings key/value table (see App\Services\StockUnits
 * - the schema stays frozen). ?q= and ?stock= remain dead.
 *
 * Phase 3 (Step 1): the item-type CASE lookup (batch expiry tracking) and
 * the whole receive pipeline - four validations, manual beginTransaction /
 * commit / rollBack, batch merge, stock movement log and audit row - moved
 * into App\Services\StockService, injected below. The delete / set_level
 * actions, the list queries and the redirect/flash stay here.
 */
class InventoryController extends Controller
{
    public function __construct(protected StockService $stockService) {}

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

        $errors = [];
        $levelError = null;

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

            // Item-type CASE lookup (batch expiry tracking) moved verbatim into
            // App\Services\StockService (Phase 3, Step 1). It still runs for
            // every POST with a selected item - query order unchanged - and the
            // posted expiry is still only read for non-first_aid items.
            $expiry = $this->stockService->resolveExpiry($mid, $request->post('expiry_date'));

            if ($action === 'receive') {
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
                );

                if ($result['success']) {
                    return redirect()
                        ->route('staff.inventory')
                        ->with('success', 'Stock added successfully.');
                }

                $errors = $result['errors'];
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
            } elseif ($action === 'set_level') {
                // Feature: manual Full / Half Empty / Empty level for the
                // non-piece units (bottle, tube, roll, piece, ...). The value
                // is kept in the existing system_settings key/value table
                // (key stock_level_{medicine_id}) - the schema is frozen, so
                // no column is invented. An unknown level or item is rejected
                // without touching the database.
                $level = (string) $request->post('level', '');

                if (StockUnits::setLevel($mid, $level)) {
                    AuditLogger::log(
                        (int) $request->user()->id,
                        'set_stock_level',
                        'medicines',
                        $mid,
                        'Stock level for medicine #'.$mid.' set to '.StockUnits::levelLabel($level),
                    );

                    return redirect()
                        ->route('staff.inventory')
                        ->with('success', 'Stock level updated.');
                }
                $levelError = 'Invalid stock level selection.';
            }
        }

        // ---- Inventory list (original lines 115-141) ---------------------
        // Feature: the item-type filter gains a visible form field and a
        // second, category-level filter built from the existing
        // medicine_categories tree (First Aid / Wound Care vs Medicine, plus
        // every leaf category). ?q= and ?stock= stay dead (parity).
        $filterType = in_array($request->query('item_type', ''), ['all', 'medicine', 'first_aid'], true)
            ? $request->query('item_type')
            : 'all';

        // Roots (parent_id NULL) sort first, then their children - portable
        // across MySQL and SQLite, which both order NULLs first on ASC.
        $categories = DB::select(
            'SELECT id, parent_id, name, type FROM medicine_categories ORDER BY type, parent_id, id',
        );
        $parentOf = [];
        foreach ($categories as $cat) {
            $parentOf[(int) $cat->id] = $cat->parent_id !== null ? (int) $cat->parent_id : 0;
        }
        $filterCategory = (int) $request->query('category', 0);
        if (! array_key_exists($filterCategory, $parentOf)) {
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

        $inventorySql = 'SELECT m.id, m.name AS medicine_name, m.unit, m.low_stock_threshold,
        c.name AS category_name,
        CASE WHEN c.type = "first_aid" THEN "first_aid" ELSE "medicine" END AS item_type,
        COALESCE(SUM(b.quantity), 0) AS total_qty,
        COUNT(b.id) AS batch_count,
        MAX(b.date_received) AS last_received,
        MIN(CASE WHEN b.expiry_date IS NOT NULL AND b.expiry_date >= CURDATE() THEN b.expiry_date END) AS next_expiry_date,
        MAX(CASE WHEN b.expiry_date IS NOT NULL AND b.expiry_date < CURDATE() THEN 1 ELSE 0 END) AS has_expired
        FROM medicines m
        LEFT JOIN medicine_categories c ON c.id = m.category_id
        LEFT JOIN medicine_batches b ON b.medicine_id = m.id AND b.quantity > 0
        WHERE m.is_active = 1';

        $where = [];
        $params = [];
        if ($filterType !== 'all') {
            $where[] = 'CASE WHEN c.type = "first_aid" THEN "first_aid" ELSE "medicine" END = ?';
            $params[] = $filterType;
        }
        if ($categoryFilterIds !== []) {
            $where[] = 'm.category_id IN ('.implode(',', array_fill(0, count($categoryFilterIds), '?')).')';
            foreach ($categoryFilterIds as $categoryId) {
                $params[] = $categoryId;
            }
        }
        if ($where) {
            $inventorySql .= ' AND '.implode(' AND ', $where);
        }
        $inventorySql .= ' GROUP BY m.id, m.name, m.unit, m.low_stock_threshold, c.name, c.type
        ORDER BY m.name ASC';
        $inventoryRows = DB::select($inventorySql, $params);

        // Reference quirk: this loop's results are never read - the view
        // recomputes every variable - but $today comes from here.
        $filteredInventory = [];
        $today = date('Y-m-d');
        foreach ($inventoryRows as $row) {
            $rowTotal = (int) ($row->total_qty ?? 0);
            $threshold = max(1, (int) ($row->low_stock_threshold ?? 1));
            $nextExpiry = $row->next_expiry_date ?? null;
            $hasExpired = (int) ($row->has_expired ?? 0) === 1;
            $isExpiringSoon = $nextExpiry && strtotime($nextExpiry) >= strtotime($today) && strtotime($nextExpiry) <= strtotime('+30 days');

            $filteredInventory[] = $row;
        }

        return view('staff.inventory', [
            'title' => 'Inventory',
            'shell' => ShellContext::fromUser($request->user(), activePath: 'staff/inventory'),
            'errors' => $errors,
            'levelError' => $levelError,
            'medicines' => $medicines,
            'suppliers' => $suppliers,
            'q' => $request->query('q', ''),
            'stock' => $request->query('stock', ''),
            'filterType' => $filterType,
            'filterCategory' => $filterCategory,
            'categories' => $categories,
            'filteredInventory' => $filteredInventory,
            'stockStates' => StockUnits::states($filteredInventory),
            'today' => $today,
        ]);
    }
}

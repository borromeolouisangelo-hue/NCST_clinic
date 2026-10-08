<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminSupplierService;
use App\Support\ShellContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin - Suppliers (admin/suppliers.php).
 *
 * Same single-URL shape as admin/categories.php (Phase 25): GET list/search +
 * legacy ?action=delete (PRG redirect) + ?action=edit (modal renders CLOSED
 * with prefills - no reopen script, reference quirk); POST save with a
 * name-required check ONLY - the reference has NO duplicate-supplier check
 * (unlike categories) and no other validation, so two suppliers may share a
 * name. fire_event: none on this page in the reference.
 *
 * CSRF: the reference page runs no check and its form carries no token field
 * - the global middleware answers 419 on token-less POSTs instead (approved
 * Phase 6 strengthening; the view carries @csrf so the form saves as in the
 * reference). Browser QA never sends a valid token here: a successful save
 * would write live suppliers - the save paths are Feature-test only.
 *
 * ACCEPTED: TrimStrings normalizes query/body strings before our trim()
 * (identical outcome for the UI's exact values); action/id read via
 * query() like every prior controller (crafted whitespace enum edge
 * documented in Phases 23-25).
 *
 * Phase 5 (Group A): the save pipeline (single name check + update/create
 * + audit) and the delete + audit moved into
 * App\Services\AdminSupplierService, injected below - this method keeps
 * field/action reads, the list query and rendering.
 */
class AdminSuppliersController extends Controller
{
    public function __construct(protected AdminSupplierService $adminSupplierService) {}

    public function index(Request $request): View|RedirectResponse
    {
        $errors = [];
        $action = (string) $request->query('action', 'list');
        $id = (int) $request->query('id', 0);

        // ---- Handle POST (original lines 11-35): field reads stay here;
        // the single name validation, update/create and audit moved into
        // App\Services\AdminSupplierService (Phase 5, Group A). ----
        if ($request->isMethod('post')) {
            $sid = (int) $request->input('id', 0);
            $name = trim((string) $request->input('name', ''));
            $person = trim((string) $request->input('contact_person', ''));
            $phone = trim((string) $request->input('phone', ''));
            $email = trim((string) $request->input('email', ''));
            $address = trim((string) $request->input('address', ''));

            $result = $this->adminSupplierService->save($sid, $name, $person, $phone, $email, $address, (int) $request->user()->id);

            if ($result['success']) {
                return redirect()->route('admin.suppliers')
                    ->with('success', $result['created'] ? 'Supplier created.' : 'Supplier updated.');
            }

            $errors = $result['errors'];
            // Validation failure: fall through to the list render below.
        }

        // ---- Legacy GET delete (original lines 37-42): PRG, no existence check ----
        // (delete + audit moved into App\Services\AdminSupplierService.)
        if ($action === 'delete' && $id > 0) {
            $this->adminSupplierService->delete($id, (int) $request->user()->id);

            return redirect()->route('admin.suppliers')->with('success', 'Supplier deleted.');
        }

        // ---- Load edit row (original lines 44-49) ----
        $edit = null;
        if ($action === 'edit' && $id > 0) {
            $row = DB::selectOne('SELECT * FROM suppliers WHERE id = ? LIMIT 1', [$id]);
            $edit = $row ? (array) $row : null;
        }

        // ---- List data (original lines 51-61) ----
        $search = trim((string) $request->query('q', ''));

        $sql = 'SELECT * FROM suppliers';
        $params = [];
        if ($search !== '') {
            $sql .= ' WHERE name LIKE ? OR contact_person LIKE ? OR phone LIKE ? OR email LIKE ? OR address LIKE ?';
            array_push($params, "%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%");
        }
        $sql .= ' ORDER BY name';

        $suppliers = array_map(
            static fn ($row): array => (array) $row,
            DB::select($sql, $params)
        );

        return view('admin.suppliers', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'admin/suppliers'),
            'title' => 'Suppliers',
            'supplierErrors' => $errors,
            'edit' => $edit,
            'suppliers' => $suppliers,
            'search' => $search,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminMedicineService;
use App\Support\ShellContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin medicines catalog - port of admin/medicines.php (read-only reference).
 *
 * require_login(['admin']) becomes ['auth', 'role:admin'] route middleware;
 * the custom CSRF failure branch (flash + redirect) is Laravel's 419
 * (approved Phase 6 difference). All other branches - the two-rule
 * validation order, the LOWER() duplicate check, update-vs-insert, the
 * GET delete/edit actions with no existence checks, the search SQL - run
 * exactly like the reference. set_flash('success', ...) becomes ->with() :
 * written but never rendered (write-only legacy slot, see partials/flash).
 *
 * Phase 5 (Group A): the save pipeline (two-rule validations incl. the
 * unit default + LOWER() duplicate check + update/create + audit) and the
 * delete + audit moved into App\Services\AdminMedicineService, injected
 * below - this method keeps field/action reads, the queries and rendering.
 */
class MedicineController extends Controller
{
    public function __construct(protected AdminMedicineService $adminMedicineService) {}

    public function index(Request $request): View|RedirectResponse
    {
        $errors = [];

        // ---- Store / update (original lines 15-55): field reads stay
        // here; the two-rule validations (incl. the unit default), the
        // LOWER() duplicate check, update/create and audit moved into
        // App\Services\AdminMedicineService (Phase 5, Group A). ----
        if ($request->isMethod('post')) {
            $mid = (int) $request->post('id', 0);
            $name = trim($request->post('name') ?? '');
            $generic = trim($request->post('generic_name') ?? '');
            $catId = (int) ($request->post('category_id') ?? 0) ?: null;
            $unit = trim($request->post('unit') ?? '');
            $desc = trim($request->post('description') ?? '');
            $thresh = (int) ($request->post('low_stock_threshold') ?? 10);
            $active = $request->has('is_active') ? 1 : 0;

            $result = $this->adminMedicineService->save(
                $mid,
                $name,
                $generic,
                $catId,
                $unit,
                $desc,
                $thresh,
                $active,
                (int) $request->user()->id,
            );

            if ($result['success']) {
                return redirect()
                    ->route('admin.medicines')
                    ->with('success', $result['created'] ? 'Medicine created.' : 'Medicine updated.');
            }

            $errors = $result['errors'];
        }

        // ---- Delete (original lines 57-62): a plain GET, no existence check.
        // (delete + audit moved into App\Services\AdminMedicineService.)
        if ($request->query('action') === 'delete' && ($mid = (int) $request->query('id', 0)) > 0) {
            $this->adminMedicineService->delete($mid, (int) $request->user()->id);

            return redirect()
                ->route('admin.medicines')
                ->with('success', 'Medicine deleted.');
        }

        // ---- Edit context (original lines 64-69) --------------------------
        $edit = null;
        if ($request->query('action') === 'edit' && ($mid = (int) $request->query('id', 0)) > 0) {
            $edit = DB::selectOne('SELECT * FROM medicines WHERE id = ? LIMIT 1', [$mid]);
        }

        $categories = DB::select('SELECT * FROM medicine_categories ORDER BY name');

        // ---- List + search (original lines 75-91) -------------------------
        $search = trim($request->query('q') ?? '');
        $sql = 'SELECT m.*, c.name AS category_name,
                    COALESCE((SELECT SUM(b.quantity) FROM medicine_batches b
                              WHERE b.medicine_id = m.id
                                AND b.quantity > 0
                                AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())),0) AS stock
             FROM medicines m
             LEFT JOIN medicine_categories c ON c.id = m.category_id';
        $params = [];
        if ($search !== '') {
            $sql .= ' WHERE m.name LIKE ? OR m.generic_name LIKE ?';
            $params = ["%$search%", "%$search%"];
        }
        $sql .= ' ORDER BY m.name';
        $medicines = DB::select($sql, $params);

        return view('admin.medicines', [
            'title' => 'Medicines',
            'shell' => ShellContext::fromUser($request->user(), activePath: 'admin/medicines'),
            'errors' => $errors,
            'edit' => $edit,
            'categories' => $categories,
            'search' => $search,
            'medicines' => $medicines,
        ]);
    }
}

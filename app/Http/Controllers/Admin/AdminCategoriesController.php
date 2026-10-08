<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminCategoryService;
use App\Support\ShellContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin - Medicine categories (admin/categories.php).
 *
 * One URL behind require_login(['admin']): GET list/search + legacy
 * ?action=delete (which DOES redirect here - PRG, unlike admin/users.php's
 * fall-through) + ?action=edit (loads the row; the modal renders CLOSED with
 * prefills - the reference has no reopen script for it and main.js has no
 * categoryModal logic, a quirk kept verbatim). POST save creates/updates
 * with a name-required check and a per-type duplicate check, then PRG.
 *
 * CSRF: the reference page runs no check and its form carries no token
 * field - the global middleware answers 419 on token-less POSTs instead
 * (approved Phase 6 strengthening; the view carries @csrf so the form saves
 * as in the reference). Browser QA never sends a valid token here: a
 * successful save would write live medicine_categories - the save paths are
 * Feature-test only (isolated SQLite).
 *
 * Legacy behaviors preserved on purpose:
 *  - The toolbar's type <select> submits ?type=... but the reference SQL
 *    NEVER filters by it (only the selected-state reads it, raw) - display
 *    quirk kept: no WHERE on type is added here either.
 *  - $categoryOptions (SELECT * FROM medicine_categories ORDER BY name) is
 *    assigned in the reference and never read anywhere - dead code dropped,
 *    same as Phase 22's $actions DISTINCT query.
 *  - fire_event('category.created') is not ported (listener-less, Phase 2).
 *
 * ACCEPTED: TrimStrings normalizes whitespace-padded enum POST values
 * (type) before the strict whitelist comparison; the UI only posts exact
 * values. The GET selected-state reads the RAW request URI like Phases 22/23.
 *
 * Phase 5 (Group A): the save pipeline (name check + per-type duplicate
 * COUNT + update/create + audit + Notify) and the delete + audit moved
 * into App\Services\AdminCategoryService, injected below - this method
 * keeps field/action reads, the list query and rendering.
 */
class AdminCategoriesController extends Controller
{
    public function __construct(protected AdminCategoryService $adminCategoryService) {}

    public function index(Request $request): View|RedirectResponse
    {
        $errors = [];
        $action = (string) $request->query('action', 'list');
        $id = (int) $request->query('id', 0);

        // ---- Handle POST (original lines 11-40): field reads stay here;
        // validation, per-type duplicate check, update/create, audit and
        // Notify moved into App\Services\AdminCategoryService (Phase 5, Group A). ----
        if ($request->isMethod('post')) {
            $name = trim((string) $request->input('name', ''));
            $desc = trim((string) $request->input('description', ''));
            $type = in_array($request->input('type', 'medicine'), ['medicine', 'first_aid'], true)
                ? (string) $request->input('type')
                : 'medicine';
            $cid = (int) $request->input('id', 0);

            $result = $this->adminCategoryService->save($name, $desc, $type, $cid, (int) $request->user()->id);

            if ($result['success']) {
                return redirect()->route('admin.categories')
                    ->with('success', $result['created'] ? 'Category created.' : 'Category updated.');
            }

            $errors = $result['errors'];
            // Errors: fall through to the list render (action forced to the
            // modal's edit/add content below via $edit = null / the row).
        }

        // ---- Legacy GET delete (original lines 42-47): PRG, no existence check ----
        // (delete + audit moved into App\Services\AdminCategoryService.)
        if ($action === 'delete' && $id > 0) {
            $this->adminCategoryService->delete($id, (int) $request->user()->id);

            return redirect()->route('admin.categories')->with('success', 'Category deleted.');
        }

        // ---- Load edit row (original lines 49-54) ----
        $edit = null;
        if ($action === 'edit' && $id > 0) {
            $row = DB::selectOne('SELECT * FROM medicine_categories WHERE id = ? LIMIT 1', [$id]);
            $edit = $row ? (array) $row : null;
        }

        // ---- List data (original lines 56-67): q drives the WHERE; the
        // type <select> never filters (reference quirk - see header). ----
        $search = trim((string) $request->query('q', ''));

        // RAW query values, as PHP's $_GET would hold them (selected-state).
        $rawQuery = [];
        parse_str((string) (explode('?', $request->getRequestUri(), 2)[1] ?? ''), $rawQuery);
        $rawType = $rawQuery['type'] ?? '';

        $sql = 'SELECT c.*, (SELECT COUNT(*) FROM medicines m WHERE m.category_id = c.id) AS med_count
                FROM medicine_categories c';
        $params = [];
        if ($search !== '') {
            $sql .= ' WHERE c.name LIKE ? OR c.description LIKE ? OR c.type LIKE ?';
            array_push($params, "%{$search}%", "%{$search}%", "%{$search}%");
        }
        $sql .= ' ORDER BY c.name';

        $cats = array_map(
            static fn ($row): array => (array) $row,
            DB::select($sql, $params)
        );

        return view('admin.categories', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'admin/categories'),
            'title' => 'Medicine Categories',
            'categoryErrors' => $errors,
            'edit' => $edit,
            'cats' => $cats,
            'search' => $search,
            'rawType' => $rawType,
        ]);
    }
}

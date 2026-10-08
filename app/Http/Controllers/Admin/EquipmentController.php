<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminEquipmentService;
use App\Support\ShellContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin equipment inventory - port of admin/equipment.php (read-only
 * reference).
 *
 * require_login(['admin']) becomes ['auth', 'role:admin'] route middleware.
 * The reference page carries no CSRF check at all (and no token field), so
 * the port keeps only the global middleware: a token-less POST raises 419
 * instead of being accepted (approved Phase 6 security strengthening).
 *
 * Everything else runs like the reference, in its exact order:
 *
 *  - the POST create/update block first (a validation failure falls through
 *    to the same GET rendering); updates recompute available_qty as
 *    total minus the previously borrowed units (total - available), and a
 *    missing id renders "Equipment record not found." without writing;
 *  - then GET ?action=delete, which performs NO existence check - a
 *    zero-row DELETE still writes the audit row and flashes success;
 *  - then GET ?action=edit, whose fetch() miss leaves $edit null so the
 *    ADD modal renders instead of an edit form (reference quirk);
 *  - ?q= trims and filters name/description/condition_note; ?condition=
 *    is echoed into the toolbar select but NEVER filters (dead input).
 *
 * set_flash('success', ...) becomes ->with(...) : written but never
 * rendered (write-only legacy slot, see partials/flash.blade.php).
 *
 * Phase 5 (Group A): the save pipeline (two validations + row re-read +
 * availability recompute + update/create + audit) and the delete + audit
 * moved into App\Services\AdminEquipmentService, injected below - this
 * method keeps field/action reads, the queries and rendering.
 */
class EquipmentController extends Controller
{
    public function __construct(protected AdminEquipmentService $adminEquipmentService) {}

    public function index(Request $request): View|RedirectResponse
    {
        $errors = [];

        // ---- Create / update (original lines 11-47): field reads stay
        // here; the two validations, the row re-read + availability
        // recompute, update/create and audit moved into
        // App\Services\AdminEquipmentService (Phase 5, Group A). ----
        if ($request->isMethod('post')) {
            $eid = (int) $request->post('id', 0);
            $name = trim($request->post('name') ?? '');
            $desc = trim($request->post('description') ?? '');
            $total = (int) ($request->post('total_qty') ?? 1);
            // The form always submits condition_note: an empty field stays
            // empty in the reference ($_POST[...] ?? 'good' only fires when
            // the key is missing), so the default is keyed on presence.
            $cond = $request->has('condition_note')
                ? trim((string) $request->post('condition_note'))
                : 'good';
            $active = $request->has('is_active') ? 1 : 0;

            $result = $this->adminEquipmentService->save(
                $eid,
                $name,
                $desc,
                $total,
                $cond,
                $active,
                (int) $request->user()->id,
            );

            if ($result['success']) {
                return redirect()
                    ->route('admin.equipment')
                    ->with('success', $result['created'] ? 'Equipment created.' : 'Equipment updated.');
            }

            $errors = $result['errors'];
        }

        // ---- ?action=delete (original lines 49-54) ----------------------
        // No existence check on purpose: an unknown id still runs the
        // zero-row DELETE, audits and flashes success, then redirects.
        // (delete + audit moved into App\Services\AdminEquipmentService.)
        if (($request->query('action') ?? '') === 'delete'
            && ($eid = (int) ($request->query('id') ?? 0)) > 0) {
            $this->adminEquipmentService->delete($eid, (int) $request->user()->id);

            return redirect()
                ->route('admin.equipment')
                ->with('success', 'Equipment deleted.');
        }

        // ---- ?action=edit (original lines 56-61) ------------------------
        // A missing row leaves $edit null: the modal renders in ADD mode.
        $edit = null;
        if (($request->query('action') ?? '') === 'edit'
            && ($eid = (int) ($request->query('id') ?? 0)) > 0) {
            $edit = DB::selectOne('SELECT * FROM equipment WHERE id=? LIMIT 1', [$eid]);
        }

        // ---- List + search (original lines 63-73) -----------------------
        $search = trim($request->query('q') ?? '');
        $sql = 'SELECT * FROM equipment';
        $params = [];
        if ($search !== '') {
            $sql .= ' WHERE name LIKE ? OR description LIKE ? OR condition_note LIKE ?';
            $params = ["%$search%", "%$search%", "%$search%"];
        }
        $sql .= ' ORDER BY name';
        $equipment = DB::select($sql, $params);

        return view('admin.equipment', [
            'title' => 'Equipment',
            'shell' => ShellContext::fromUser($request->user(), activePath: 'admin/equipment'),
            'errors' => $errors,
            'edit' => $edit,
            'equipment' => $equipment,
            'q' => $search,
            'condition' => $request->query('condition') ?? '',
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Notify;
use App\Services\UserAdminService;
use App\Support\ShellContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin - User account management (admin/users.php).
 *
 * One URL behind require_login(['admin']) with three GET branches (list /
 * edit / delete) and two POST handlers (save / toggle), exactly like the
 * reference. GET ?action=delete is a legacy state change kept verbatim - it
 * does NOT redirect, it falls through to the list render (the reference's
 * set_flash() writes are never rendered anywhere: no flash renderer exists).
 * save/toggle PRG with ->with('success'|'danger', ...).
 *
 * CSRF: the reference soft-fails invalid tokens into flash + redirect; the
 * global middleware answers 419 instead (approved Phase 6 strengthening).
 * The reference's toggle form and New-User modal form carry NO token field
 * in the original markup (and main.js injects none), so those two POSTs
 * dead-end at 419 here where the original silently redirected to the list;
 * the edit form carries @csrf and works on both sides.
 *
 * fire_event('user.created', ...) is not ported (listener-less, Phase 2
 * decision); log_action() becomes AuditLogger::log().
 *
 * ACCEPTED: Laravel's TrimStrings middleware trims request inputs, so
 * whitespace-padded enum values in crafted POSTs (role/account_type/status)
 * normalize before the reference's strict comparisons - the UI only ever
 * posts exact values. The GET values keep the reference's split: `q` is
 * trimmed (for SQL and for the input value) while `role` stays RAW (both
 * the whitelist check that feeds SQL and the <select> selected-state read
 * $_GET raw in the reference) - recovered from the request URI like Phase 22
 * because TrimStrings rewrites the Request query bag.
 *
 * Phase 3 (Step 2): the save pipeline (five validations, duplicate check,
 * manual create/update transaction with profiles upsert, account_type sync
 * and audit row) and the toggle pipeline (self-deactivation guard, status
 * CASE update, audit row) moved into App\Services\UserAdminService,
 * injected below. Notify, the redirects, the legacy GET delete and the
 * list/edit queries stay here.
 */
class AdminUsersController extends Controller
{
    public function __construct(protected UserAdminService $userAdminService) {}

    public function index(Request $request): View|RedirectResponse
    {
        $action = (string) $request->query('action', 'list');
        $id = (int) $request->query('id', 0);
        $errors = [];

        // ---- Handle POST (original lines 16-121) ----
        if ($request->isMethod('post')) {
            $postAction = (string) $request->input('form_action', '');
            $uid = (int) $request->input('id', 0);
            $actor = (int) $request->user()->id;

            if ($postAction === 'save') {
                $username = trim((string) $request->input('username', ''));
                $email = trim((string) $request->input('email', ''));
                $role = in_array($request->input('role', ''), ['user', 'staff', 'admin'], true)
                    ? (string) $request->input('role')
                    : 'user';
                $account_type = ((string) $request->input('account_type', 'student')) === 'employee' ? 'employee' : 'student';
                $status = ((string) $request->input('status', 'active')) === 'inactive' ? 'inactive' : 'active';
                $first = trim((string) $request->input('first_name', ''));
                $last = trim((string) $request->input('last_name', ''));
                // Password is read RAW - 'password' is excluded from
                // TrimStrings by the framework (Phase 20 precedent), matching
                // the reference's untrimmed $_POST['password'].
                $password = (string) $request->input('password', '');

                // Five input validations, the duplicate check and the manual
                // transaction (create/update branch, profiles upsert,
                // sync_account_type, audit row) moved verbatim into
                // App\Services\UserAdminService (Phase 3, Step 2). Notify,
                // redirect and the fall-through below stay here.
                $result = $this->userAdminService->save(
                    uid: $uid,
                    username: $username,
                    email: $email,
                    role: $role,
                    account_type: $account_type,
                    status: $status,
                    first: $first,
                    last: $last,
                    password: $password,
                    actor: $actor,
                );

                if ($result['success']) {
                    // Feature 2: new accounts are announced to every admin
                    // account (the "System (Staff)" switch), never to the
                    // account that was just created. Deduped on the user id.
                    if ($result['created']) {
                        Notify::toRoles(['admin'], 'user_created', [
                            'reference_id' => $result['newId'],
                            'severity' => 'info',
                            'title' => 'New User Account',
                            'message' => "Account '{$username}' ({$role}) was created.",
                            'action_url' => 'admin/users',
                        ]);
                    }

                    return redirect()
                        ->route('admin.users')
                        ->with('success', $result['created'] ? 'User created successfully.' : 'User updated successfully.');
                }

                $errors = $result['errors'];

                // Fall through to form with errors (original lines 105-107)
                $action = ($uid > 0) ? 'edit' : 'new';
                $id = $uid;
            }

            if ($postAction === 'toggle') {
                $uid = (int) $request->input('id', 0);

                // Self-deactivation guard + status CASE update + audit row
                // moved into App\Services\UserAdminService (Phase 3, Step 2);
                // response formatting (danger vs success flash) stays here.
                $toggleError = $this->userAdminService->toggle($uid, $actor);
                if ($toggleError !== null) {
                    return redirect()
                        ->route('admin.users')
                        ->with('danger', $toggleError);
                }

                return redirect()
                    ->route('admin.users')
                    ->with('success', 'Account status updated.');
            }
        }

        // ---- Delete (original lines 124-133): plain GET, no existence check,
        // and NO redirect - the reference falls through to the list render. ----
        if ($action === 'delete' && $id > 0) {
            if ($id === (int) $request->user()->id) {
                session()->flash('danger', 'You cannot delete your own account.');
            } else {
                DB::table('users')->where('id', $id)->delete();
                AuditLogger::log(
                    (int) $request->user()->id,
                    'delete_user',
                    'users',
                    $id,
                    "Deleted user #{$id}"
                );
                session()->flash('success', 'User deleted.');
            }
        }

        // ---- Load form data (original lines 137-141) ----
        $editUser = null;
        if ($action === 'edit' && $id > 0) {
            $row = DB::selectOne(
                'SELECT u.*, p.first_name, p.last_name FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE u.id = ? LIMIT 1',
                [$id]
            );
            $editUser = $row ? (array) $row : null;
        }

        // ---- List data (original lines 144-159) ----
        $search = trim((string) $request->query('q', ''));

        // RAW query values, as PHP's $_GET would hold them (see header note).
        $rawQuery = [];
        parse_str((string) (explode('?', $request->getRequestUri(), 2)[1] ?? ''), $rawQuery);
        $roleFilter = $rawQuery['role'] ?? '';

        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = '(u.username LIKE ? OR u.email LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ?)';
            array_push($params, "%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%");
        }
        if (in_array($roleFilter, ['user', 'staff', 'admin'], true)) {
            $where[] = 'u.role = ?';
            $params[] = $roleFilter;
        }
        $whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

        // Feature: Session status - determine if user is currently logged in.
        // A user is "Logged In" if their most recent audit_logs action is 'login'
        // and there's no subsequent 'logout'. We compute this via a correlated
        // subquery that finds the latest session action per user.
        $sql = "
            SELECT u.*, p.first_name, p.last_name,
                   CASE WHEN (
                       SELECT al.action FROM audit_logs al
                       WHERE al.user_id = u.id
                       AND al.action IN ('login', 'logout')
                       ORDER BY al.created_at DESC LIMIT 1
                   ) = 'login' THEN 1 ELSE 0 END AS is_logged_in
            FROM users u
            LEFT JOIN profiles p ON p.user_id = u.id
            {$whereSql}
            ORDER BY u.role, u.username
        ";

        $users = array_map(
            static fn ($row): array => (array) $row,
            DB::select($sql, $params)
        );

        return view('admin.users', [
            'shell' => ShellContext::fromUser($request->user(), activePath: 'admin/users'),
            'title' => 'User Accounts',
            'action' => $action,
            'id' => $id,
            'userErrors' => $errors,
            'editUser' => $editUser,
            'users' => $users,
            'search' => $search,
            'rawRole' => $roleFilter,
            'oldInput' => $request->post(),
        ]);
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Admin user account management - extracted verbatim from
 * App\Http\Controllers\Admin\AdminUsersController::index() (Phase 3, Step 2).
 *
 * The controller keeps the HTTP concerns: reading the POST fields, the
 * form_action dispatch (save / toggle), Notify, the redirects (PRG + the
 * legacy fall-through to the form), the plain GET delete and the list /
 * edit queries. This service owns the domain pipelines:
 *
 *  - save(): the five input validations (username, valid email, first name,
 *    last name, password min 6 for new users), the duplicate check
 *    (username/email, excluding the row being edited), then the manual
 *    transaction (beginTransaction / commit / rollBack) holding the update
 *    or create branch, the profiles upsert, the sync_account_type() helper
 *    call and the audit row. The update branch still throws on a short
 *    non-empty password so the fixed 'Save failed: {message}' form error
 *    surfaces exactly as before.
 *
 *  - toggle(): the self-deactivation guard, the status CASE WHEN update
 *    (the identical standard-SQL form for MySQL + SQLite) and its audit
 *    row; returns the original error string or null on success so the
 *    controller keeps response formatting.
 *
 * Error strings, SQL text, error-array order and transaction boundaries are
 * byte-identical to the controller's originals; only $request->user()->id
 * became the injected $actor (same value, resolved once by the controller).
 */
class UserAdminService
{
    /**
     * Run the save pipeline: validations -> duplicate check -> manual
     * transaction (create/update + profile upsert + account_type sync +
     * audit).
     *
     * @param  int  $uid  target user id, 0 for a new account
     * @param  string  $username  trimmed username
     * @param  string  $email  trimmed email
     * @param  string  $role  whitelisted role (user|staff|admin)
     * @param  string  $account_type  student|employee
     * @param  string  $status  active|inactive
     * @param  string  $first  trimmed first name
     * @param  string  $last  trimmed last name
     * @param  string  $password  raw password (TrimStrings-excluded, see
     *                            controller header); '' = keep existing
     * @param  int  $actor  authenticated admin id ($request->user()->id)
     * @return array{success: bool, created: bool, newId: int|null, errors: array<int, string>}
     */
    public function save(
        int $uid,
        string $username,
        string $email,
        string $role,
        string $account_type,
        string $status,
        string $first,
        string $last,
        string $password,
        int $actor,
    ): array {
        $errors = [];

        if ($username === '') {
            $errors[] = 'Username is required.';
        }
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Valid email is required.';
        }
        if ($first === '') {
            $errors[] = 'First name is required.';
        }
        if ($last === '') {
            $errors[] = 'Last name is required.';
        }
        if ($uid === 0 && strlen($password) < 6) {
            $errors[] = 'Password (min 6 chars) is required for new users.';
        }

        if (! $errors) {
            $dup = (int) DB::scalar(
                'SELECT COUNT(*) FROM users WHERE (username = ? OR email = ?) AND id <> ?',
                [$username, $email, $uid]
            );
            if ($dup > 0) {
                $errors[] = 'Username or email already exists.';
            }
        }

        $savedCreated = false;
        $newId = null;
        if (! $errors) {
            try {
                DB::beginTransaction();

                if ($uid > 0) {
                    // Update user
                    $update = [
                        'username' => $username,
                        'email' => $email,
                        'role' => $role,
                        'account_type' => $account_type,
                        'status' => $status,
                    ];
                    if ($password !== '') {
                        if (strlen($password) < 6) {
                            throw new \Exception('Password must be at least 6 characters.');
                        }
                        $update['password_hash'] = Hash::make($password);
                    }
                    DB::table('users')->where('id', $uid)->update($update);

                    // Profile upsert (reference: SELECT COUNT, then INSERT or UPDATE)
                    if ((int) DB::table('profiles')->where('user_id', $uid)->count() === 0) {
                        DB::table('profiles')->insert([
                            'user_id' => $uid,
                            'first_name' => $first,
                            'last_name' => $last,
                        ]);
                    } else {
                        DB::table('profiles')->where('user_id', $uid)->update([
                            'first_name' => $first,
                            'last_name' => $last,
                        ]);
                    }

                    // Sync account_type with student/employee details presence.
                    sync_account_type($uid, $account_type);

                    AuditLogger::log($actor, 'update_user', 'users', $uid, "Updated user #{$uid}");
                } else {
                    // Create user
                    $newId = (int) DB::table('users')->insertGetId([
                        'username' => $username,
                        'email' => $email,
                        'password_hash' => Hash::make($password),
                        'role' => $role,
                        'account_type' => $account_type,
                        'status' => $status,
                    ]);
                    DB::table('profiles')->insert([
                        'user_id' => $newId,
                        'first_name' => $first,
                        'last_name' => $last,
                    ]);

                    // Sync account_type with student/employee details presence.
                    sync_account_type($newId, $account_type);

                    AuditLogger::log($actor, 'create_user', 'users', $newId, "Created user {$username} ({$role})");
                    // fire_event('user.created', ...) - not ported (Phase 2).

                    $savedCreated = true;
                }

                DB::commit();
            } catch (\Exception $ex) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                $errors[] = 'Save failed: '.$ex->getMessage();
            }
        }

        return [
            'success' => ! $errors,
            'created' => $savedCreated,
            'newId' => $newId,
            'errors' => $errors,
        ];
    }

    /**
     * Run the status toggle pipeline: self-deactivation guard, then the
     * status CASE WHEN update and its audit row.
     *
     * @param  int  $uid  target user id
     * @param  int  $actor  authenticated admin id (also the "own account"
     *                      check value, exactly like the original branch)
     * @return string|null the original error message when refused, null on success
     */
    public function toggle(int $uid, int $actor): ?string
    {
        if ($uid === $actor) {
            return 'You cannot deactivate your own account.';
        }

        // Reference: UPDATE users SET status = IF(status='active','inactive','active')
        // - CASE WHEN is the identical standard-SQL form (MySQL + SQLite).
        DB::table('users')->where('id', $uid)->update([
            'status' => DB::raw("CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END"),
        ]);
        AuditLogger::log($actor, 'toggle_user', 'users', $uid, 'Toggled account status');

        return null;
    }
}

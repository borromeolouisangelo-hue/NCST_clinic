<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 23 admin/users.php tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects (DB_CONNECTION=sqlite, DB_DATABASE=:memory:).
 *
 * Covers require_login(['admin']) access, the list render (row order
 * u.role+u.username, name fallback, role chips, type/status cells, action
 * links incl. the escaped &), the trimmed 4-way search, the RAW role filter
 * + selected-state quirk, both edit-form branches (prefills and id=999
 * fallbacks), save (create/edit/validation/dup/short-password), sync of
 * student_details/employee_details, toggle (self/other/missing) and the
 * legacy GET delete (self/existing/missing). CREATE and mutating paths run
 * ONLY here - the live users table is never written by browser tests.
 */
class AdminUsersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 50)->unique();
            $table->string('email', 120)->unique();
            $table->string('password_hash');
            $table->string('role', 20)->default('user');
            $table->string('account_type', 20)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('first_name', 100)->nullable();
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('birthdate')->nullable();
            $table->string('address')->nullable();
            $table->string('contact_no', 30)->nullable();
            $table->string('emergency_name')->nullable();
            $table->string('emergency_contact', 30)->nullable();
            $table->string('photo')->nullable();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('loan_id')->nullable();
            $table->string('reference_id', 50)->nullable();
            $table->string('type', 50)->nullable();
            $table->string('severity', 20)->nullable();
            $table->string('icon', 50)->nullable();
            $table->string('title');
            $table->text('message');
            $table->string('action_url')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'is_read']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 50)->nullable();
            $table->string('table_name', 60)->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->string('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key', 100)->unique();
            $table->string('setting_value')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });

        // sync_account_type() creates/removes these (admin/users.php save path).
        Schema::create('student_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('student_no', 30)->default('');
        });

        Schema::create('employee_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('employee_no', 30)->default('');
        });
    }

    // ---------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------

    public function test_admin_renders_guest_redirects_and_wrong_roles_bounce(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);
        $staff = $this->makeUser('staffx', 'staff', 'employee');
        $student = $this->makeUser('mstudent', 'user', 'student');

        $this->get('/admin/users')->assertRedirect(route('login'));
        $this->actingAs($admin)->get('/admin/users')->assertOk();
        $this->actingAs($staff)->get('/admin/users')->assertRedirect(route('staff.dashboard'));
        $this->actingAs($student)->get('/admin/users')->assertRedirect(route('user.dashboard'));
    }

    // ---------------------------------------------------------------------
    // List render
    // ---------------------------------------------------------------------

    public function test_filtered_empty_list_renders_toolbar_table_and_no_reopen_script(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);

        $response = $this->actingAs($admin)->get('/admin/users?q=zzz');
        $response->assertOk();
        $response->assertSee('href="'.url('admin/users').'" class="nav-link active">', false);
        $response->assertSee('<form method="get" class="row g-2 align-items-end audit-toolbar">', false);
        $response->assertSee('value="zzz"', false);
        $response->assertSee('placeholder="Name, username or email"', false);
        $response->assertSee('<table id="usersTable" class="table table-hover mb-0 align-middle">', false);
        $response->assertSee('<tr><th>#</th><th>Name</th><th>Username</th><th>Email</th>', false);
        $response->assertSee('<td colspan="9" class="text-center text-muted py-4">No users found.</td>', false);
        $response->assertSee('<option value="">All Roles</option>', false);
        $response->assertSee('<option value="user" >User</option>', false);
        $response->assertSee('data-bs-target="#newUserModal"', false);
        // No toolbar option is selected; the modal's User option legitimately
        // carries selected on a GET (original behaves identically).
        $response->assertDontSee('<option value="staff" selected>', false);
        $response->assertDontSee('<option value="admin" selected>', false);
        // Clean list: the modal auto-reopen script only fires for action=new or errors.
        $response->assertDontSee('new bootstrap.Modal(modal).show()', false);
    }

    public function test_rows_render_in_role_username_order_with_chips_badges_and_actions(): void
    {
        $admin = $this->makeUser('adminx', 'admin', 'student', 'Boss', 'Person');
        $staff = $this->makeUser('zstaff', 'staff', 'employee', 'Zoe', 'Staff');
        $user = $this->makeUser('auser', 'user', 'student', 'Ann', 'User');
        $ghost = $this->makeUser('nop', 'user', 'student', null, null);   // no profile row -> em-dash
        DB::table('users')->where('id', $ghost->id)->update(['status' => 'inactive']);

        $response = $this->actingAs($admin)->get('/admin/users');
        $response->assertOk();
        $content = (string) $response->getContent();

        // Row order: ORDER BY u.role, u.username -> admin < staff < user.
        $tbody = substr($content, (int) strpos($content, '<tbody>'));
        $pAdmin = strpos($tbody, '<td>adminx</td>');
        $pStaff = strpos($tbody, '<td>zstaff</td>');
        $pUser = strpos($tbody, '<td>auser</td>');
        $pGhost = strpos($tbody, '<td>nop</td>');
        $this->assertTrue($pAdmin !== false && $pStaff !== false && $pUser !== false && $pGhost !== false);
        $this->assertTrue($pAdmin < $pStaff && $pStaff < $pUser && $pUser < $pGhost);

        // Names from profiles, em-dash fallback, emails, role chips, type, statuses.
        $this->assertStringContainsString('<td>Boss Person</td>', $content);
        $this->assertStringContainsString('<td>Zoe Staff</td>', $content);
        $this->assertStringContainsString('<td>Ann User</td>', $content);
        $this->assertSame(1, substr_count($content, "<td>\xe2\x80\x94</td>"));
        $this->assertStringContainsString('<td>adminx@school.test</td>', $content);
        $this->assertStringContainsString('<span class="chip">Administrator</span>', $content);
        $this->assertStringContainsString('<span class="chip">Clinic Staff</span>', $content);
        $this->assertStringContainsString('<span class="chip">User</span>', $content);
        $this->assertStringContainsString('<td>Student</td>', $content);
        $this->assertStringContainsString('<td>Employee</td>', $content);
        $this->assertSame(3, substr_count($content, '<span class="badge bg-success">Active</span>'));
        $this->assertSame(1, substr_count($content, '<span class="badge bg-secondary">Inactive</span>'));

        // Action links + inline toggle form (escaped & in the hrefs).
        $this->assertStringContainsString('admin/users?action=edit&amp;id=', $content);
        $this->assertStringContainsString('admin/users?action=delete&amp;id=', $content);
        $this->assertStringContainsString('name="form_action" value="toggle"', $content);
        $this->assertStringContainsString('title="Toggle status"', $content);
        $this->assertStringContainsString('data-swal-delete="user"', $content);
    }

    // ---------------------------------------------------------------------
    // Search + role filter
    // ---------------------------------------------------------------------

    public function test_search_matches_username_email_and_profile_names(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);
        $this->makeUser('alice', 'user', 'student', 'Alice', 'Smith');
        $bob = $this->makeUser('bob', 'user', 'student', 'Bob', 'Jones');

        $r = $this->actingAs($admin)->get('/admin/users?q=Smith');   // last_name LIKE
        $r->assertSee('Alice', false);
        $r->assertDontSee('<td>bob</td>', false);

        $r = $this->actingAs($admin)->get('/admin/users?q=bob@school.test');   // email LIKE
        $r->assertSee('<td>bob</td>', false);
        $r->assertDontSee('<td>alice</td>', false);

        $r = $this->actingAs($admin)->get('/admin/users?q=alice');   // username LIKE
        $r->assertSee('<td>alice</td>', false);
        $r->assertDontSee('<td>bob</td>', false);

        $r = $this->actingAs($admin)->get('/admin/users?q=zzzz');
        $r->assertSee('No users found.', false);
    }

    public function test_role_filter_filters_rows_and_selects_the_option(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);
        $this->makeUser('zstaff', 'staff', 'employee');
        $this->makeUser('auser', 'user', 'student');

        $response = $this->actingAs($admin)->get('/admin/users?role=staff');
        $response->assertSee('<td>zstaff</td>', false);
        $response->assertDontSee('<td>adminx</td>', false);
        $response->assertDontSee('<td>auser</td>', false);
        $response->assertSee('<option value="staff" selected>Clinic Staff</option>', false);
        $response->assertSee('<option value="user" >User</option>', false);
    }

    public function test_raw_role_value_filters_nothing_and_selects_nothing(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);
        $this->makeUser('zstaff', 'staff', 'employee');
        $this->makeUser('auser', 'user', 'student');

        // Raw ' staff ' fails the reference's strict whitelist -> NO SQL
        // filter and NO selected option, while q-style values stay raw too.
        $response = $this->actingAs($admin)->get('/admin/users?role=%20staff%20');
        $response->assertSee('<td>adminx</td>', false);
        $response->assertSee('<td>zstaff</td>', false);
        $response->assertSee('<td>auser</td>', false);
        $response->assertSee('<option value="staff" >Clinic Staff</option>', false);
        $response->assertDontSee('<option value="staff" selected>', false);
    }

    // ---------------------------------------------------------------------
    // Edit form
    // ---------------------------------------------------------------------

    public function test_edit_form_prefills_from_the_database_row(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);
        $user = $this->makeUser('auser', 'user', 'student', 'Ann', 'User');
        DB::table('users')->where('id', $user->id)->update(['email' => 'ann@school.test', 'status' => 'inactive']);

        $response = $this->actingAs($admin)->get("/admin/users?action=edit&id={$user->id}");
        $response->assertOk();
        $response->assertSee('Back to list', false);
        $response->assertSee('value="auser"', false);
        $response->assertSee('value="ann@school.test"', false);
        $response->assertSee('value="Ann"', false);
        $response->assertSee('value="User"', false);
        // Selected states: role user, account_type student, status inactive.
        $response->assertSee('<option value="user" selected>User</option>', false);
        $response->assertSee('<option value="student" selected>Student</option>', false);
        $response->assertSee('<option value="inactive" selected>Inactive</option>', false);
        $response->assertSee('Password (leave blank to keep)', false);
        // Password input carries no required attribute in edit mode.
        $response->assertSee('name="password" class="form-control" >', false);
        $response->assertSee('name="_token"', false);
        // List branch is hidden while editing.
        $response->assertDontSee('usersTable', false);
    }

    public function test_edit_missing_id_falls_back_to_empty_form_defaults(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);

        $response = $this->actingAs($admin)->get('/admin/users?action=edit&id=999');
        $response->assertOk();
        $response->assertSee('Back to list', false);
        $response->assertSee('value="" required', false);          // first_name empty fallback
        $response->assertSee('value="0"', false);                   // hidden id fallback
        $response->assertSee('<option value="user" selected>User</option>', false);
        $response->assertSee('<option value="student" selected>Student</option>', false);
        $response->assertSee('<option value="active" selected>Active</option>', false);
        $response->assertSee('Password (leave blank to keep)', false);
    }

    // ---------------------------------------------------------------------
    // Save (create)
    // ---------------------------------------------------------------------

    public function test_save_new_creates_user_profile_syncs_details_and_logs(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);

        $response = $this->actingAs($admin)->post('/admin/users', [
            'form_action' => 'save',
            'id' => 0,
            'username' => 'newbie',
            'email' => 'newbie@school.test',
            'role' => 'staff',
            'account_type' => 'employee',
            'status' => 'inactive',
            'first_name' => 'New',
            'last_name' => 'Student',
            'password' => 'secret123',
        ]);

        $response->assertRedirect(route('admin.users'));
        $response->assertSessionHas('success', 'User created successfully.');

        $created = DB::table('users')->where('username', 'newbie')->first();
        $this->assertNotNull($created);
        $this->assertSame('staff', $created->role);
        $this->assertSame('employee', $created->account_type);
        $this->assertSame('inactive', $created->status);
        $this->assertTrue(Hash::check('secret123', $created->password_hash));

        $profile = DB::table('profiles')->where('user_id', $created->id)->first();
        $this->assertSame('New', $profile->first_name);
        $this->assertSame('Student', $profile->last_name);

        // account_type=employee -> employee_details row created, none for student.
        $this->assertSame(1, DB::table('employee_details')->where('user_id', $created->id)->count());
        $this->assertSame(0, DB::table('student_details')->where('user_id', $created->id)->count());

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'create_user',
            'record_id' => $created->id,
        ]);
    }

    public function test_save_new_validation_errors_render_in_modal_without_writing(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);
        $before = DB::table('users')->count();
        $beforeProfiles = DB::table('profiles')->count();

        $response = $this->actingAs($admin)->post('/admin/users', [
            'form_action' => 'save',
            'id' => 0,
            'username' => 'bob',
            'email' => 'not-an-email',
            'role' => 'user',
            'account_type' => 'student',
            'status' => 'active',
            'first_name' => '',
            'last_name' => '',
            'password' => 'abc',
        ]);

        $response->assertOk();   // falls through to the list + open modal
        $response->assertSee('Valid email is required.', false);
        $response->assertSee('First name is required.', false);
        $response->assertSee('Last name is required.', false);
        $response->assertSee('Password (min 6 chars) is required for new users.', false);
        $response->assertDontSee('Username is required.', false);
        // Errors render in the modal loop only (action=new -> list branch).
        $this->assertSame(4, substr_count((string) $response->getContent(), '<div class="alert alert-danger py-2">'));
        // Posted values repopulate the modal fields.
        $response->assertSee('value="bob"', false);
        // Modal auto-reopens.
        $response->assertSee('new bootstrap.Modal(modal).show()', false);
        // Nothing was written.
        $this->assertSame($before, DB::table('users')->count());
        $this->assertSame($beforeProfiles, DB::table('profiles')->count());
    }

    public function test_save_new_rejects_duplicate_username_and_email(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);
        $this->makeUser('alice', 'user', 'student', 'Alice', 'Smith');
        $before = DB::table('users')->count();

        $base = [
            'form_action' => 'save', 'id' => 0, 'role' => 'user',
            'account_type' => 'student', 'status' => 'active',
            'first_name' => 'X', 'last_name' => 'Y', 'password' => 'secret123',
        ];

        $this->actingAs($admin)->post('/admin/users', $base + ['username' => 'alice', 'email' => 'fresh@school.test'])
            ->assertSee('Username or email already exists.', false);

        $this->actingAs($admin)->post('/admin/users', $base + ['username' => 'fresh', 'email' => 'alice@school.test'])
            ->assertSee('Username or email already exists.', false);

        $this->assertSame($before, DB::table('users')->count());
    }

    // ---------------------------------------------------------------------
    // Save (edit)
    // ---------------------------------------------------------------------

    public function test_save_edit_updates_user_and_profile(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);
        $user = $this->makeUser('auser', 'user', 'student', 'Ann', 'User');

        $response = $this->actingAs($admin)->post('/admin/users', [
            'form_action' => 'save',
            'id' => $user->id,
            'username' => 'auser2',
            'email' => 'auser2@school.test',
            'role' => 'staff',
            'account_type' => 'employee',
            'status' => 'inactive',
            'first_name' => 'Anna',
            'last_name' => 'Updated',
            'password' => '',   // keep existing password
        ]);

        $response->assertRedirect(route('admin.users'));
        $response->assertSessionHas('success', 'User updated successfully.');

        $row = DB::table('users')->find($user->id);
        $this->assertSame('auser2', $row->username);
        $this->assertSame('auser2@school.test', $row->email);
        $this->assertSame('staff', $row->role);
        $this->assertSame('employee', $row->account_type);
        $this->assertSame('inactive', $row->status);
        $this->assertTrue(Hash::check('secret123', $row->password_hash));   // untouched

        $profile = DB::table('profiles')->where('user_id', $user->id)->first();
        $this->assertSame('Anna', $profile->first_name);
        $this->assertSame('Updated', $profile->last_name);

        $this->assertSame(1, DB::table('employee_details')->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'update_user', 'record_id' => $user->id]);
    }

    public function test_save_edit_short_password_fails_in_transaction_without_writing(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);
        $user = $this->makeUser('auser', 'user', 'student', 'Ann', 'User');

        $response = $this->actingAs($admin)->post('/admin/users', [
            'form_action' => 'save',
            'id' => $user->id,
            'username' => 'changed',
            'email' => 'changed@school.test',
            'role' => 'admin',
            'account_type' => 'student',
            'status' => 'active',
            'first_name' => 'Nope',
            'last_name' => 'Nope',
            'password' => 'abc',
        ]);

        $response->assertOk();   // falls back to the edit form with errors
        $response->assertSee('Save failed: Password must be at least 6 characters.', false);
        $response->assertSee('Back to list', false);

        // The throw happens before any statement - nothing changed.
        $row = DB::table('users')->find($user->id);
        $this->assertSame('auser', $row->username);
        $this->assertSame('user', $row->role);
        $profile = DB::table('profiles')->where('user_id', $user->id)->first();
        $this->assertSame('Ann', $profile->first_name);
    }

    // ---------------------------------------------------------------------
    // Toggle
    // ---------------------------------------------------------------------

    public function test_toggle_blocks_self_toggles_other_and_ignores_missing(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);
        $other = $this->makeUser('auser', 'user', 'student');

        // Self: blocked before any write.
        $this->actingAs($admin)->post('/admin/users', ['form_action' => 'toggle', 'id' => $admin->id])
            ->assertRedirect(route('admin.users'))
            ->assertSessionHas('danger', 'You cannot deactivate your own account.');
        $this->assertSame('active', DB::table('users')->find($admin->id)->status);

        // Another user: flipped.
        $this->actingAs($admin)->post('/admin/users', ['form_action' => 'toggle', 'id' => $other->id])
            ->assertRedirect(route('admin.users'))
            ->assertSessionHas('success', 'Account status updated.');
        $this->assertSame('inactive', DB::table('users')->find($other->id)->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'toggle_user', 'record_id' => $other->id]);

        // Missing id: same success flash, no rows touched.
        $this->actingAs($admin)->post('/admin/users', ['form_action' => 'toggle', 'id' => 999])
            ->assertRedirect(route('admin.users'))
            ->assertSessionHas('success', 'Account status updated.');
    }

    // ---------------------------------------------------------------------
    // Legacy GET delete
    // ---------------------------------------------------------------------

    public function test_delete_blocks_self_deletes_existing_and_ignores_missing(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);
        $victim = $this->makeUser('auser', 'user', 'student');

        // Self: blocked, still rendered (no redirect), user intact.
        $this->actingAs($admin)->get("/admin/users?action=delete&id={$admin->id}")
            ->assertOk()
            ->assertSessionHas('danger', 'You cannot delete your own account.');
        $this->assertNotNull(DB::table('users')->find($admin->id));

        // Existing other user: deleted, falls through to the list render.
        $this->actingAs($admin)->get("/admin/users?action=delete&id={$victim->id}")
            ->assertOk()
            ->assertSessionHas('success', 'User deleted.');
        $this->assertNull(DB::table('users')->find($victim->id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'delete_user', 'record_id' => $victim->id]);

        // Missing id: no existence check - same success flash, still a list render.
        $this->actingAs($admin)->get('/admin/users?action=delete&id=999')
            ->assertOk()
            ->assertSessionHas('success', 'User deleted.');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeUser(
        string $username,
        string $role = 'user',
        ?string $accountType = 'student',
        ?string $first = 'Old',
        ?string $last = 'Name'
    ): User {
        $user = User::create([
            'username' => $username,
            'email' => $username.'@school.test',
            'password_hash' => Hash::make('secret123'),
            'role' => $role,
            'account_type' => $accountType,
            'status' => 'active',
        ]);

        if ($first !== null || $last !== null) {
            Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last]);
        }

        return $user->fresh();
    }
}

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
 * Phase 20 user/settings.php tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects (DB_CONNECTION=sqlite, DB_DATABASE=:memory:).
 *
 * Covers Account Settings behind require_login(['user']): the role:user
 * allow-list (students/employees in, admin/staff bounced to their own
 * dashboards), the Account card's users.* read + byte-for-byte badge, the
 * password modal chrome, the exact three-message validation with its
 * append order, the $reopen gating (top alert block vs modal + reopen
 * script, messages render exactly once), the RAW (untrimmed) password
 * reads, the PRG flash, the password_hash swap and the change_password
 * audit row. Token-less POST -> 419 is middleware (browser-verified;
 * Feature tests bypass VerifyCsrfToken - documented ACCEPTED).
 */
class SettingsTest extends TestCase
{
    private const OLD_PASSWORD = 'oldpass123';

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

        // Shell pieces the shared layout reads.
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

        // Audit rows the success path writes (created_at owned by the DB
        // default, exactly like the live table).
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
    }

    // ---------------------------------------------------------------------
    // Access: role:user only, Account card, modal chrome
    // ---------------------------------------------------------------------

    public function test_role_user_accounts_render_and_guest_and_other_roles_bounce(): void
    {
        $student = $this->makeUser('studentx', 'user', 'student');
        $employee = $this->makeUser('employeex', 'user', 'employee');
        $admin = $this->makeUser('adminx', 'admin', null);
        $staff = $this->makeUser('staffx', 'staff', 'employee');

        $this->get('/user/settings')->assertRedirect(route('login'));
        $this->actingAs($student)->get('/user/settings')->assertOk();
        $this->actingAs($employee)->get('/user/settings')->assertOk();
        // require_login(['user']) - wrong buckets go to their OWN dashboard.
        $this->actingAs($admin)->get('/user/settings')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($staff)->get('/user/settings')->assertRedirect(route('staff.dashboard'));
    }

    public function test_account_card_reads_users_row_and_byte_for_byte_badge(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');

        $response = $this->actingAs($student)->get('/user/settings');
        $response->assertOk();
        $response->assertSee('<div class="card-header"><i class="bi bi-person-badge me-1"></i> Account</div>', false);
        $response->assertSee('<dt class="col-sm-4">Username</dt>', false);
        $response->assertSee('<dd class="col-sm-8">mstudent</dd>', false);
        $response->assertSee('<dt class="col-sm-4">Email</dt>', false);
        $response->assertSee('<dd class="col-sm-8">mstudent@school.test</dd>', false);
        $response->assertSee('<dd class="col-sm-8">STUDENT</dd>', false);
        $response->assertSee('<dd class="col-sm-8 text-capitalize">student</dd>', false);
        $response->assertSee('<dd class="col-sm-8 text-capitalize">active</dd>', false);
        $response->assertSee('<div class="card-header"><i class="bi bi-shield-lock me-1"></i> Security</div>', false);
        $response->assertSee('data-bs-target="#passwordModal"', false);
    }

    public function test_employee_badge_and_no_alerts_on_clean_get(): void
    {
        $employee = $this->makeUser('memployee', 'user', 'employee');

        $response = $this->actingAs($employee)->get('/user/settings');
        $response->assertOk();
        $response->assertSee('<dd class="col-sm-8">EMPLOYEE</dd>', false);
        $response->assertSee('<dd class="col-sm-8 text-capitalize">employee</dd>', false);
        // Clean render: no error alerts anywhere, no modal-reopen script.
        $response->assertDontSee('<div class="alert alert-danger py-2">', false);
        $response->assertDontSee('new bootstrap.Modal(modal).show()', false);
    }

    public function test_password_modal_renders_full_chrome(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');

        $response = $this->actingAs($student)->get('/user/settings');
        $response->assertOk();
        $response->assertSee('<!-- Password Reset Modal -->', false);
        $response->assertSee('<div class="modal fade" id="passwordModal"', false);
        $response->assertSee('id="passwordModalHeader"', false);
        $response->assertSee('<form method="post" data-swal-save="password">', false);
        $response->assertSee('name="_token"', false);
        $response->assertSee('<input type="hidden" name="form_action" value="password">', false);
        $response->assertSee('<input type="password" name="current_password" class="form-control" required>', false);
        $response->assertSee('<input type="password" name="new_password" class="form-control" minlength="6" required>', false);
        $response->assertSee('<div class="form-text">Must be at least 6 characters.</div>', false);
        $response->assertSee('<input type="password" name="confirm_password" class="form-control" minlength="6" required>', false);
        $response->assertSee('</i> Update Password</button>', false);
        $response->assertSee('data-bs-dismiss="modal">Cancel</button>', false);
    }

    // ---------------------------------------------------------------------
    // Validation: exact messages, order, $reopen gating, no write
    // ---------------------------------------------------------------------

    public function test_wrong_current_password_renders_once_in_modal_not_top(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');

        $response = $this->actingAs($student)->post('/user/settings', [
            'form_action' => 'password',
            'current_password' => 'not-the-password',
            'new_password' => 'newpass123',
            'confirm_password' => 'newpass123',
        ]);

        $content = (string) $response->getContent();
        $response->assertOk();
        $response->assertSee('Current password is incorrect.', false);
        // Exactly once (modal block), reopen script present, top-level alert
        // block suppressed by $reopen (its 8-space lead never emitted).
        $this->assertSame(1, substr_count($content, 'Current password is incorrect.'));
        $response->assertSee('new bootstrap.Modal(modal).show()', false);
        $response->assertDontSee("\n        <div class=\"alert alert-danger py-2\">", false);
        // Account card still renders the stored row (read before POST).
        $response->assertSee('<dd class="col-sm-8">mstudent</dd>', false);
        // No write, no audit.
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, DB::table('users')->where('id', $student->id)->value('password_hash')));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_empty_or_short_new_password_reports_only_the_length_error(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');

        $response = $this->actingAs($student)->post('/user/settings', [
            'form_action' => 'password',
            'current_password' => self::OLD_PASSWORD,
            'new_password' => '',
            'confirm_password' => '',
        ]);

        $response->assertOk();
        $response->assertSee('New password must be at least 6 characters.', false);
        $response->assertDontSee('Current password is incorrect.', false);
        $response->assertDontSee('New passwords do not match.', false);
        $response->assertSee('new bootstrap.Modal(modal).show()', false);
    }

    public function test_mismatching_confirmation_reports_only_the_mismatch_error(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');

        $response = $this->actingAs($student)->post('/user/settings', [
            'form_action' => 'password',
            'current_password' => self::OLD_PASSWORD,
            'new_password' => '123456',
            'confirm_password' => '654321',
        ]);

        $response->assertOk();
        $response->assertSee('New passwords do not match.', false);
        $response->assertDontSee('Current password is incorrect.', false);
        $response->assertDontSee('New password must be at least 6 characters.', false);
    }

    public function test_all_three_errors_append_in_the_original_order_once_each(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');

        $response = $this->actingAs($student)->post('/user/settings', [
            'form_action' => 'password',
            'current_password' => 'wrong-current',
            'new_password' => 'abc',
            'confirm_password' => 'xyz',
        ]);

        $content = (string) $response->getContent();
        $response->assertOk();
        $first = strpos($content, 'Current password is incorrect.');
        $second = strpos($content, 'New password must be at least 6 characters.');
        $third = strpos($content, 'New passwords do not match.');
        $this->assertNotFalse($first);
        $this->assertNotFalse($second);
        $this->assertNotFalse($third);
        $this->assertTrue($first < $second && $second < $third);
        $this->assertSame(1, substr_count($content, 'Current password is incorrect.'));
        $this->assertSame(1, substr_count($content, 'New password must be at least 6 characters.'));
        $this->assertSame(1, substr_count($content, 'New passwords do not match.'));
        // Top-level block stays suppressed; modal block + reopen script own them.
        $response->assertDontSee("\n        <div class=\"alert alert-danger py-2\">", false);
        $response->assertSee('new bootstrap.Modal(modal).show()', false);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_foreign_form_action_falls_through_to_the_plain_render(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');
        $before = DB::table('users')->where('id', $student->id)->value('password_hash');

        $response = $this->actingAs($student)->post('/user/settings', [
            'form_action' => 'something_else',
            'new_password' => 'whatever1',
            'confirm_password' => 'whatever1',
        ]);

        $response->assertOk();
        $response->assertDontSee('New password must be at least 6 characters.', false);
        $response->assertDontSee('new bootstrap.Modal(modal).show()', false);
        $this->assertSame($before, DB::table('users')->where('id', $student->id)->value('password_hash'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    // ---------------------------------------------------------------------
    // Success: hash swap, PRG flash, audit, still authenticated
    // ---------------------------------------------------------------------

    public function test_successful_change_swaps_hash_pr_g_redirects_and_audits(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');

        $response = $this->actingAs($student)->post('/user/settings', [
            'form_action' => 'password',
            'current_password' => self::OLD_PASSWORD,
            'new_password' => 'brandnew1',
            'confirm_password' => 'brandnew1',
        ]);

        $response->assertRedirect(route('user.settings'));
        $response->assertSessionHas('success', 'Password updated successfully.');
        $this->assertAuthenticated();

        $hash = DB::table('users')->where('id', $student->id)->value('password_hash');
        $this->assertTrue(Hash::check('brandnew1', $hash));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $hash));

        $this->assertSame(1, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $student->id,
            'action' => 'change_password',
            'table_name' => 'users',
            'record_id' => $student->id,
            'details' => 'Changed own password',
        ]);
    }

    public function test_password_reads_are_raw_untrimmed(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');

        // A trailing-space current password must NOT verify against the
        // trimmed stored one (original reads $_POST raw - no trim).
        $response = $this->actingAs($student)->post('/user/settings', [
            'form_action' => 'password',
            'current_password' => self::OLD_PASSWORD.' ',
            'new_password' => 'brandnew1',
            'confirm_password' => 'brandnew1',
        ]);

        $response->assertOk();
        $response->assertSee('Current password is incorrect.', false);
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, DB::table('users')->where('id', $student->id)->value('password_hash')));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeUser(string $username, string $role, ?string $accountType): User
    {
        $user = User::create([
            'username' => $username,
            'email' => $username.'@school.test',
            'password_hash' => Hash::make(self::OLD_PASSWORD),
            'role' => $role,
            'account_type' => $accountType,
            'status' => 'active',
        ]);

        Profile::create(['user_id' => $user->id, 'first_name' => 'Old', 'last_name' => 'Name']);

        return $user->fresh();
    }
}

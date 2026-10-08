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
 * Phase 29 staff/settings.php tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects (DB_CONNECTION=sqlite, DB_DATABASE=:memory:).
 *
 * Covers Account Settings behind require_login(['staff']): the staff-only
 * allow-list (staff in, admin/student/employee bounced to their own
 * dashboards), the Account card's users.* read + byte-for-byte STAFF badge,
 * the INLINE password form chrome (the staff page has no modal and no
 * reopen script - errors render once in the unconditional top block), the
 * exact three-message validation with its append order, the RAW (untrimmed)
 * password reads, the foreign form_action fall-through, the PRG flash, the
 * password_hash swap and the change_password audit row. Token-less POST ->
 * 419 is middleware (browser-verified; Feature tests bypass VerifyCsrfToken
 * - documented ACCEPTED).
 */
class StaffSettingsTest extends TestCase
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

        // Audit rows the success path writes.
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
    // Access: role:staff only, Account card, inline form chrome
    // ---------------------------------------------------------------------

    public function test_role_staff_renders_guest_and_other_roles_bounce(): void
    {
        $staff = $this->makeUser('staffx', 'staff', 'employee');
        $admin = $this->makeUser('adminx', 'admin', null);
        $student = $this->makeUser('studentx', 'user', 'student');
        $employee = $this->makeUser('employeex', 'user', 'employee');

        $this->get('/staff/settings')->assertRedirect(route('login'));
        $this->actingAs($staff)->get('/staff/settings')->assertOk();
        // require_login(['staff']) - wrong buckets go to their OWN dashboard.
        $this->actingAs($admin)->get('/staff/settings')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($student)->get('/staff/settings')->assertRedirect(route('user.dashboard'));
        $this->actingAs($employee)->get('/staff/settings')->assertRedirect(route('user.dashboard'));
    }

    public function test_account_card_reads_users_row_and_staff_badge(): void
    {
        $staff = $this->makeUser('staffx', 'staff', 'employee');

        $response = $this->actingAs($staff)->get('/staff/settings');
        $response->assertOk()
            ->assertSee('<div class="card-header"><i class="bi bi-person-badge me-1"></i> Account</div>', false)
            ->assertSee('<dt class="col-sm-4">Username</dt>', false)
            ->assertSee('<dd class="col-sm-8">staffx</dd>', false)
            ->assertSee('<dt class="col-sm-4">Email</dt>', false)
            ->assertSee('<dd class="col-sm-8">staffx@school.test</dd>', false)
            ->assertSee('<dd class="col-sm-8">STAFF</dd>', false)
            ->assertSee('<dd class="col-sm-8 text-capitalize">employee</dd>', false)
            ->assertSee('<dd class="col-sm-8 text-capitalize">active</dd>', false)
            ->assertSee('<div class="card-header"><i class="bi bi-shield-lock me-1"></i> Security</div>', false);
    }

    public function test_inline_form_renders_without_modal_and_clean_get_has_no_alerts(): void
    {
        $staff = $this->makeUser('staffx', 'staff', 'employee');

        $response = $this->actingAs($staff)->get('/staff/settings');
        $response->assertOk()
            // Inline form in the Security card (original L79-96) - NO modal.
            ->assertSee('<form method="post" data-swal-save="password">', false)
            ->assertSee('name="_token"', false)
            ->assertSee('<input type="hidden" name="form_action" value="password">', false)
            ->assertSee('<input type="password" name="current_password" class="form-control" required>', false)
            ->assertSee('<input type="password" name="new_password" class="form-control" minlength="6" required>', false)
            ->assertSee('<div class="form-text">Must be at least 6 characters.</div>', false)
            ->assertSee('<input type="password" name="confirm_password" class="form-control" minlength="6" required>', false)
            ->assertSee('<i class="bi bi-save me-1"></i> Update Password</button>', false);
        // No modal chrome, no reopen script, no alerts on a clean GET.
        $response->assertDontSee('passwordModal', false);
        $response->assertDontSee('data-bs-target="#passwordModal"', false);
        $response->assertDontSee('bootstrap.Modal', false);
        $response->assertDontSee('<div class="alert alert-danger py-2">', false);
    }

    // ---------------------------------------------------------------------
    // Validation: exact messages, order, unconditional top block, no write
    // ---------------------------------------------------------------------

    public function test_wrong_current_password_renders_once_in_the_top_block(): void
    {
        $staff = $this->makeUser('staffx', 'staff', 'employee');

        $response = $this->actingAs($staff)->post('/staff/settings', [
            'form_action' => 'password',
            'current_password' => 'not-the-password',
            'new_password' => 'newpass123',
            'confirm_password' => 'newpass123',
        ]);

        $content = (string) $response->getContent();
        $response->assertOk();
        $response->assertSee('Current password is incorrect.', false);
        // Exactly once: the staff page has ONE (unconditional) error block.
        $this->assertSame(1, substr_count($content, 'Current password is incorrect.'));
        // Account card still renders the stored row (read before POST).
        $response->assertSee('<dd class="col-sm-8">staffx</dd>', false);
        // No write, no audit.
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, DB::table('users')->where('id', $staff->id)->value('password_hash')));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_empty_or_short_new_password_reports_only_the_length_error(): void
    {
        $staff = $this->makeUser('staffx', 'staff', 'employee');

        $response = $this->actingAs($staff)->post('/staff/settings', [
            'form_action' => 'password',
            'current_password' => self::OLD_PASSWORD,
            'new_password' => '',
            'confirm_password' => '',
        ]);

        $response->assertOk();
        $response->assertSee('New password must be at least 6 characters.', false);
        $response->assertDontSee('Current password is incorrect.', false);
        $response->assertDontSee('New passwords do not match.', false);
    }

    public function test_mismatching_confirmation_reports_only_the_mismatch_error(): void
    {
        $staff = $this->makeUser('staffx', 'staff', 'employee');

        $response = $this->actingAs($staff)->post('/staff/settings', [
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
        $staff = $this->makeUser('staffx', 'staff', 'employee');

        $response = $this->actingAs($staff)->post('/staff/settings', [
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
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_foreign_form_action_falls_through_to_the_plain_render(): void
    {
        $staff = $this->makeUser('staffx', 'staff', 'employee');
        $before = DB::table('users')->where('id', $staff->id)->value('password_hash');

        $response = $this->actingAs($staff)->post('/staff/settings', [
            'form_action' => 'something_else',
            'new_password' => 'whatever1',
            'confirm_password' => 'whatever1',
        ]);

        $response->assertOk();
        $response->assertDontSee('New password must be at least 6 characters.', false);
        $this->assertSame($before, DB::table('users')->where('id', $staff->id)->value('password_hash'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_password_reads_are_raw_untrimmed(): void
    {
        $staff = $this->makeUser('staffx', 'staff', 'employee');

        // A trailing-space current password must NOT verify against the
        // trimmed stored one (original reads $_POST raw - no trim).
        $response = $this->actingAs($staff)->post('/staff/settings', [
            'form_action' => 'password',
            'current_password' => self::OLD_PASSWORD.' ',
            'new_password' => 'brandnew1',
            'confirm_password' => 'brandnew1',
        ]);

        $response->assertOk();
        $response->assertSee('Current password is incorrect.', false);
        $this->assertTrue(Hash::check(self::OLD_PASSWORD, DB::table('users')->where('id', $staff->id)->value('password_hash')));
    }

    // ---------------------------------------------------------------------
    // Success: hash swap, PRG flash, audit, still authenticated
    // ---------------------------------------------------------------------

    public function test_successful_change_swaps_hash_pr_g_redirects_and_audits(): void
    {
        $staff = $this->makeUser('staffx', 'staff', 'employee');

        $response = $this->actingAs($staff)->post('/staff/settings', [
            'form_action' => 'password',
            'current_password' => self::OLD_PASSWORD,
            'new_password' => 'brandnew1',
            'confirm_password' => 'brandnew1',
        ]);

        $response->assertRedirect(route('staff.settings'));
        $response->assertSessionHas('success', 'Password updated successfully.');
        $this->assertAuthenticated();

        $hash = DB::table('users')->where('id', $staff->id)->value('password_hash');
        $this->assertTrue(Hash::check('brandnew1', $hash));
        $this->assertFalse(Hash::check(self::OLD_PASSWORD, $hash));

        $this->assertSame(1, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $staff->id,
            'action' => 'change_password',
            'table_name' => 'users',
            'record_id' => $staff->id,
            'details' => 'Changed own password',
        ]);
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

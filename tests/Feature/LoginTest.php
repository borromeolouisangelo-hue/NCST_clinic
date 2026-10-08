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
 * Phase 5 authentication tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects (DB_CONNECTION=sqlite, DB_DATABASE=:memory:).
 *
 * Why isolated: the one branch that CANNOT be exercised against the live
 * school_clinic database is "valid credentials + inactive account" - the live
 * data set has only active accounts, and creating an inactive row there would
 * mean writing to the users table, which is forbidden. This suite therefore
 * creates its OWN throwaway tables in setUp() (the project owns no Laravel
 * migrations, so Schema::create is done here, on sqlite only) and never
 * touches school_clinic: no connection to MySQL is ever opened by these tests.
 *
 * Everything else in here (wrong password, unknown username, success, direct
 * access, cross-role, logout) is additionally browser-tested against the real
 * MySQL database in the Phase 5 matrix - these are the fast, repeatable twins
 * of those manual checks.
 */
class LoginTest extends TestCase
{
    /** Password used by every seeded account. */
    private const PASSWORD = 'secret123';

    /**
     * Throwaway schema = the subset of the live tables the auth flow reads or
     * writes: users, profiles, failed_logins, audit_logs, system_settings
     * (login page names) and notifications (topbar unread badge).
     */
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

        // attempted_at keeps DEFAULT CURRENT_TIMESTAMP (the live default) -
        // Eloquent omits the column so sqlite stamps it, exactly as MySQL does.
        Schema::create('failed_logins', function (Blueprint $table) {
            $table->id();
            $table->string('username', 50);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('attempted_at')->useCurrent();
            $table->index('username');
            $table->index('ip_address');
        });

        // created_at likewise keeps DEFAULT CURRENT_TIMESTAMP - AuditLogger
        // inserts only user_id/action/table_name/record_id/details, like the
        // original log_action().
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action', 50);
            $table->string('table_name', 50)->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->text('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('user_id');
            $table->index('action');
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key', 100)->unique();
            $table->text('setting_value')->nullable();
            $table->timestamp('updated_at')->nullable();
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

        // Phase 7: /admin/dashboard reads these three (admin/dashboard.php
        // stats + low stock + expiring + requests today). Column shapes
        // mirror the live tables; the render test leaves them empty.
        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('generic_name', 150)->nullable();
            $table->unsignedInteger('category_id')->nullable();
            $table->string('unit', 40)->default('tablet');
            $table->string('description', 255)->nullable();
            $table->integer('low_stock_threshold')->default(10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('medicine_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('medicine_id');
            $table->string('batch_no', 60)->nullable();
            $table->string('lot_no', 60)->nullable();
            $table->unsignedInteger('supplier_id')->nullable();
            $table->integer('quantity')->default(0);
            $table->date('expiry_date')->nullable();
            $table->date('date_received');
            $table->decimal('unit_cost', 10, 2)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('medicine_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('medicine_id');
            $table->integer('quantity')->default(1);
            $table->string('reason', 255)->nullable();
            $table->string('status', 20)->default('dispensed');
            $table->boolean('allergy_flag')->default(false);
            $table->text('override_reason')->nullable();
            $table->string('remarks', 255)->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->unsignedInteger('processed_by')->nullable();
            $table->timestamp('processed_at')->nullable();
        });

        // Phase 8: /staff/dashboard reads these (staff/dashboard.php KPI
        // stats, the frequently-requested/borrowed charts and the seven
        // history sections). Column shapes mirror the live tables; the
        // render test leaves them empty.
        Schema::create('student_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('student_no', 40);
            $table->string('course', 120)->nullable();
            $table->string('year_level', 40)->nullable();
            $table->string('section', 60)->nullable();
        });

        Schema::create('employee_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('employee_no', 40);
            $table->string('department', 120)->nullable();
            $table->string('position', 120)->nullable();
        });

        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->text('complaint')->nullable();
            $table->string('blood_pressure', 20)->nullable();
            $table->decimal('temperature', 4, 1)->nullable();
            $table->integer('pulse')->nullable();
            $table->integer('respiration')->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('height_cm', 5, 2)->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('treatment')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('consultation_date')->useCurrent();
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('description', 255)->nullable();
            $table->integer('total_qty')->default(1);
            $table->integer('available_qty')->default(1);
            $table->string('condition_note', 120)->default('good');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('equipment_loans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('equipment_id');
            $table->integer('quantity')->default(1);
            $table->date('borrow_date');
            $table->timestamp('borrowed_at')->useCurrent();
            $table->date('due_date')->nullable();
            $table->date('return_date')->nullable();
            $table->string('status', 20)->default('borrowed');
            $table->string('condition_on_return', 120)->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('medical_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('cert_no', 40)->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('cert_type', 30)->default('medical');
            $table->string('purpose', 255)->nullable();
            $table->text('findings')->nullable();
            $table->text('recommendation')->nullable();
            $table->integer('rest_days')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamp('issued_at')->useCurrent();
        });

        Schema::create('sick_leaves', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->text('reason');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('attachment', 255)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();
        });

        Schema::create('medicine_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('parent_id')->nullable();
            $table->string('name', 100);
            $table->string('type', 20)->default('medicine');
            $table->string('description', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Phase 9: /user/dashboard counts both (user/dashboard.php medical
        // record completeness + allergy alert). Shapes copied from
        // PatientTest, which mirrors the live tables.
        Schema::create('medical_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('blood_type', 5)->nullable();
            $table->decimal('height_cm', 5, 2)->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->text('allergies_text')->nullable();
            $table->text('existing_conditions')->nullable();
            $table->text('current_medications')->nullable();
            $table->text('immunizations')->nullable();
            $table->string('school_year', 20);
            $table->string('semester', 10);
            $table->timestamp('submitted_at')->useCurrent();
        });

        Schema::create('user_allergies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('allergen_name', 150);
            $table->string('severity', 10)->default('mild');
            $table->string('notes', 255)->nullable();
        });

        // Same values the live database holds for the login page.
        DB::table('system_settings')->insert([
            ['setting_key' => 'school_name', 'setting_value' => 'School Clinic'],
            ['setting_key' => 'clinic_name', 'setting_value' => 'School Health Clinic'],
        ]);
    }

    // ---------------------------------------------------------------------
    // Login page
    // ---------------------------------------------------------------------

    public function test_login_page_renders_with_system_settings_and_csrf(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('Sign In');
        $response->assertSee('School Clinic');
        $response->assertSee('School Health Clinic');
        $response->assertSee('_token', false); // @csrf present (original has none)
        $response->assertDontSee('Demo accounts'); // removed in the original, stays gone
    }

    public function test_empty_credentials_get_the_original_message(): void
    {
        $response = $this->post('/login', ['username' => '', 'password' => '']);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors([
            'login' => 'Please enter your username and password.',
        ]);
        $this->assertGuest();
        $this->assertSame(0, DB::table('failed_logins')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    // ---------------------------------------------------------------------
    // Rejections - EXACT original strings, no extra writes
    // ---------------------------------------------------------------------

    public function test_wrong_password_is_rejected_and_recorded_once(): void
    {
        $this->makeUser('admin', 'Test', 'Admin', ['role' => 'admin', 'account_type' => 'employee']);

        $response = $this->post('/login', [
            'username' => 'admin',
            'password' => 'definitely-wrong',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors([
            'login' => 'Invalid username or password.',
        ]);
        $this->assertGuest();
        $this->assertSame(1, DB::table('failed_logins')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());

        // The username is repopulated for the form; the password is never kept.
        $old = session('_old_input', []);
        $this->assertSame('admin', $old['username'] ?? null);
        $this->assertArrayNotHasKey('password', $old);
    }

    public function test_unknown_username_gets_the_same_invalid_message(): void
    {
        $response = $this->post('/login', [
            'username' => 'ghost-user',
            'password' => 'whatever',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors([
            'login' => 'Invalid username or password.',
        ]);
        $this->assertGuest();
        $this->assertSame(1, DB::table('failed_logins')->count());
        $this->assertSame('ghost-user', DB::table('failed_logins')->value('username'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    /**
     * The branch that must run in isolation: correct password + inactive
     * account. Original behaviour (login.php line 34-35): the message is shown,
     * NO failed attempt is recorded and nothing is audited.
     */
    public function test_inactive_account_with_valid_password_is_rejected_without_writing_anything(): void
    {
        $this->makeUser('dormant', 'Dormant', 'Student', [
            'role' => 'user',
            'account_type' => 'student',
            'status' => 'inactive',
        ]);

        $response = $this->post('/login', [
            'username' => 'dormant',
            'password' => self::PASSWORD,
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors([
            'login' => 'Your account is inactive. Please contact the administrator.',
        ]);
        $this->assertGuest();
        $this->assertSame(0, DB::table('failed_logins')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    // ---------------------------------------------------------------------
    // Success + session identity (Laravel Auth only - no parallel state)
    // ---------------------------------------------------------------------

    public function test_successful_login_redirects_to_the_role_dashboard_and_audits(): void
    {
        $this->makeUser('admin', 'Test', 'Admin', ['role' => 'admin', 'account_type' => 'employee']);

        $response = $this->post('/login', [
            'username' => 'admin',
            'password' => self::PASSWORD,
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $response->assertSessionHas('success');
        $response->assertSessionHas('success', 'Welcome back, Test Admin!');
        $this->assertAuthenticated();

        $login = DB::table('audit_logs')->where('action', 'login')->first();
        $this->assertNotNull($login);
        $this->assertSame('users', $login->table_name);
        $this->assertSame('User logged in', $login->details);
        $this->assertSame(0, DB::table('failed_logins')->count());

        // Signed in now: the login page bounces away (original lines 7-9).
        $this->get('/login')->assertRedirect(route('admin.dashboard'));
    }

    public function test_login_lookup_also_matches_email(): void
    {
        $user = $this->makeUser('staff', 'Clinic', 'Staff', [
            'role' => 'staff',
            'account_type' => 'employee',
        ]);

        $response = $this->post('/login', [
            'username' => $user->email,
            'password' => self::PASSWORD,
        ]);

        $response->assertRedirect(route('staff.dashboard'));
        $this->assertAuthenticated();
    }

    // ---------------------------------------------------------------------
    // Direct access + cross-role (require_login's two redirects)
    // ---------------------------------------------------------------------

    public function test_guest_cannot_reach_any_dashboard(): void
    {
        foreach (['admin', 'staff', 'user'] as $role) {
            $this->get('/'.$role.'/dashboard')->assertRedirect(route('login'));
        }
    }

    public function test_wrong_role_is_redirected_to_the_own_dashboard_not_403(): void
    {
        $staff = $this->makeUser('staff', 'Clinic', 'Staff', [
            'role' => 'staff',
            'account_type' => 'employee',
        ]);

        $this->actingAs($staff)->get('/admin/dashboard')
            ->assertRedirect(route('staff.dashboard'));

        $student = $this->makeUser('student', 'Juan', 'Dela Cruz', [
            'role' => 'user',
            'account_type' => 'student',
        ]);

        $this->actingAs($student)->get('/staff/dashboard')
            ->assertRedirect(route('user.dashboard'));
    }

    public function test_each_role_can_render_its_own_dashboard(): void
    {
        $admin = $this->makeUser('admin', 'Test', 'Admin', ['role' => 'admin', 'account_type' => 'employee']);
        $staff = $this->makeUser('staff', 'Clinic', 'Staff', ['role' => 'staff', 'account_type' => 'employee']);
        $student = $this->makeUser('student', 'Juan', 'Dela Cruz', ['role' => 'user', 'account_type' => 'student']);
        $employee = $this->makeUser('employee', 'Maria', 'Santos', ['role' => 'user', 'account_type' => 'employee']);

        $this->actingAs($admin)->get('/admin/dashboard')->assertOk()->assertSee('Admin Dashboard');
        $this->actingAs($staff)->get('/staff/dashboard')->assertOk()->assertSee('Clinic Dashboard');
        $this->actingAs($student)->get('/user/dashboard')->assertOk()->assertSee('My Dashboard');
        $this->actingAs($employee)->get('/user/dashboard')->assertOk()->assertSee('My Dashboard');

        // Identity surfaces in the shell: full name from profiles, badge label.
        $this->actingAs($employee)->get('/user/dashboard')
            ->assertSee('Maria Santos')
            ->assertSee('EMPLOYEE');
    }

    // ---------------------------------------------------------------------
    // Logout: audit + invalidation + redirect, session truly gone
    // ---------------------------------------------------------------------

    public function test_logout_audits_invalidates_and_sends_the_flash(): void
    {
        $this->makeUser('admin', 'Test', 'Admin', ['role' => 'admin', 'account_type' => 'employee']);

        $this->post('/login', ['username' => 'admin', 'password' => self::PASSWORD])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticated();

        $response = $this->get('/logout');

        $response->assertRedirect(route('login'));
        $this->assertGuest();

        $logout = DB::table('audit_logs')->where('action', 'logout')->first();
        $this->assertNotNull($logout);
        $this->assertSame('users', $logout->table_name);
        $this->assertSame('User logged out', $logout->details);

        // The original sets the flash before redirecting but never renders it
        // (write-only legacy slot - see partials/flash.blade.php): the login
        // page stays alert-free, exactly like the reference.
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('You have been logged out.');

        // Session really is gone: the dashboard bounces to login again.
        $this->get('/admin/dashboard')->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** Seed one account with a profile row, exactly like the live tables. */
    private function makeUser(
        string $username,
        string $firstName,
        string $lastName,
        array $overrides = [],
    ): User {
        $user = User::create(array_merge([
            'username' => $username,
            'email' => $username.'@school.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => 'user',
            'account_type' => 'student',
            'status' => 'active',
        ], $overrides));

        Profile::create([
            'user_id' => $user->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);

        return $user->fresh();
    }
}

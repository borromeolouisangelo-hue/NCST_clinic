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
 * Phase 24 admin/settings.php tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects (DB_CONNECTION=sqlite, DB_DATABASE=:memory:).
 *
 * Covers require_login(['admin']) access, the two-card render (seeded values,
 * semester selected-state, numeric defaults when a key is absent, chrome and
 * the @csrf field), the blind 8-key upsert save (PRG + flash + update_settings
 * audit row), partial saves leaving absent keys untouched, the whitelist
 * dropping unknown POST keys, and empty values storing '' (no validation).
 * The save paths run ONLY here - browser QA never sends a valid token to
 * /admin/settings because that would write live system_settings.
 */
class AdminSettingsTest extends TestCase
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
    }

    // ---------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------

    public function test_admin_renders_guest_redirects_and_wrong_roles_bounce(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        $staff = $this->makeUser('staffx', 'staff');
        $student = $this->makeUser('mstudent', 'user');
        $employee = $this->makeUser('memployee', 'user');

        $this->get('/admin/settings')->assertRedirect(route('login'));
        $this->actingAs($admin)->get('/admin/settings')->assertOk();
        $this->actingAs($staff)->get('/admin/settings')->assertRedirect(route('staff.dashboard'));
        $this->actingAs($student)->get('/admin/settings')->assertRedirect(route('user.dashboard'));
        $this->actingAs($employee)->get('/admin/settings')->assertRedirect(route('user.dashboard'));
    }

    // ---------------------------------------------------------------------
    // Render
    // ---------------------------------------------------------------------

    public function test_render_shows_values_selected_semester_defaults_and_chrome(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        DB::table('system_settings')->insert([
            ['setting_key' => 'school_name', 'setting_value' => 'San Roque National High School'],
            ['setting_key' => 'clinic_name', 'setting_value' => 'Main Clinic'],
            ['setting_key' => 'current_semester', 'setting_value' => '2nd'],
        ]);

        $response = $this->actingAs($admin)->get('/admin/settings');
        $response->assertOk();
        $response->assertSee('href="'.url('admin/settings').'" class="nav-link active">', false);

        // Two cards + hints + save row.
        $response->assertSee('<i class="bi bi-building me-1"></i> School & Clinic Info', false);
        $response->assertSee('<i class="bi bi-sliders me-1"></i> Rules & Academic Period', false);
        $response->assertSee('Used as the default period for the semester medical form.', false);
        $response->assertSee('Default cap used when a product does not have its own stock-based limit.', false);
        $response->assertSee('Save System Settings', false);
        $response->assertSee('data-swal-save="system settings"', false);

        // Seeded values in their inputs.
        $response->assertSee('value="San Roque National High School"', false);
        $response->assertSee('value="Main Clinic"', false);

        // Missing keys: text inputs default to '', limits to 10 / 5.
        $response->assertSee('name="clinic_address" class="form-control" value=""', false);
        $response->assertSee('name="current_school_year" class="form-control" value=""', false);
        $response->assertSee('name="default_dispense_limit" class="form-control" value="10"', false);
        $response->assertSee('name="default_equipment_loan_limit" class="form-control" value="5"', false);

        // Semester select: data value selected, others not.
        $response->assertSee('<option value="2nd" selected>2nd Semester</option>', false);
        $response->assertSee('<option value="1st" >1st Semester</option>', false);
        $response->assertSee('<option value="summer" >Summer Semester</option>', false);

        // @csrf present, error loop never renders.
        $response->assertSee('name="_token"', false);
        $response->assertDontSee('<div class="alert alert-danger py-2">', false);
    }

    public function test_render_defaults_semester_to_first_when_key_absent(): void
    {
        $admin = $this->makeUser('adminx', 'admin');

        $response = $this->actingAs($admin)->get('/admin/settings');
        $response->assertSee('<option value="1st" selected>1st Semester</option>', false);
        $response->assertDontSee('<option value="2nd" selected>', false);
    }

    // ---------------------------------------------------------------------
    // Save (blind 8-key upsert)
    // ---------------------------------------------------------------------

    public function test_full_save_upserts_all_keys_logs_and_prg(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        DB::table('system_settings')->insert([
            ['setting_key' => 'school_name', 'setting_value' => 'Old School'],
            ['setting_key' => 'clinic_name', 'setting_value' => 'Old Clinic'],
        ]);

        $response = $this->actingAs($admin)->post('/admin/settings', [
            'school_name' => '  St. Mary Academy  ',
            'clinic_name' => 'Guidance Clinic',
            'clinic_address' => 'Bldg. 2, Rizal Ave.',
            'clinic_contact' => '0917-555-0100',
            'current_school_year' => '2026-2027',
            'current_semester' => 'summer',
            'default_dispense_limit' => '15',
            'default_equipment_loan_limit' => '3',
        ]);

        $response->assertRedirect(route('admin.settings'));
        $response->assertSessionHas('success', 'Settings saved.');

        // Existing keys updated, absent ones inserted (ON DUPLICATE KEY UPDATE).
        $this->assertSame(8, DB::table('system_settings')->count());
        $this->assertSame('St. Mary Academy', DB::table('system_settings')->where('setting_key', 'school_name')->value('setting_value'));
        $this->assertSame('Guidance Clinic', DB::table('system_settings')->where('setting_key', 'clinic_name')->value('setting_value'));
        $this->assertSame('2026-2027', DB::table('system_settings')->where('setting_key', 'current_school_year')->value('setting_value'));
        $this->assertSame('summer', DB::table('system_settings')->where('setting_key', 'current_semester')->value('setting_value'));
        $this->assertSame('15', DB::table('system_settings')->where('setting_key', 'default_dispense_limit')->value('setting_value'));

        // One audit row, no record target.
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'update_settings',
            'table_name' => 'system_settings',
            'record_id' => null,
            'details' => 'Updated system settings',
        ]);
    }

    public function test_partial_save_touches_only_present_keys(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        DB::table('system_settings')->insert([
            ['setting_key' => 'school_name', 'setting_value' => 'Old School'],
            ['setting_key' => 'clinic_name', 'setting_value' => 'Old Clinic'],
        ]);

        $this->actingAs($admin)->post('/admin/settings', ['school_name' => 'New School'])
            ->assertRedirect(route('admin.settings'))
            ->assertSessionHas('success', 'Settings saved.');

        $this->assertSame('New School', DB::table('system_settings')->where('setting_key', 'school_name')->value('setting_value'));
        $this->assertSame('Old Clinic', DB::table('system_settings')->where('setting_key', 'clinic_name')->value('setting_value'));
        // Absent key stays absent.
        $this->assertSame(2, DB::table('system_settings')->count());
        // The audit row still logs unconditionally.
        $this->assertDatabaseHas('audit_logs', ['action' => 'update_settings']);
    }

    public function test_non_whitelisted_keys_are_ignored(): void
    {
        $admin = $this->makeUser('adminx', 'admin');

        $this->actingAs($admin)->post('/admin/settings', [
            'school_name' => 'Kept',
            'evil_key' => 'injected',
            'setting_key' => 'hijack',
            'setting_value' => 'hijack',
        ])->assertRedirect(route('admin.settings'));

        $this->assertSame(1, DB::table('system_settings')->count());
        $this->assertSame('Kept', DB::table('system_settings')->where('setting_key', 'school_name')->value('setting_value'));
        $this->assertDatabaseMissing('system_settings', ['setting_key' => 'evil_key']);
    }

    public function test_empty_value_is_stored_as_empty_string_without_validation(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        DB::table('system_settings')->insert([
            ['setting_key' => 'school_name', 'setting_value' => 'Old School'],
        ]);

        $response = $this->actingAs($admin)->post('/admin/settings', [
            'school_name' => '',
            'current_semester' => '2nd',
        ]);

        // No validation anywhere: the save still succeeds with '' stored.
        $response->assertRedirect(route('admin.settings'));
        $response->assertSessionHas('success', 'Settings saved.');
        $this->assertSame('', (string) DB::table('system_settings')->where('setting_key', 'school_name')->value('setting_value'));
        $this->assertSame('2nd', DB::table('system_settings')->where('setting_key', 'current_semester')->value('setting_value'));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeUser(string $username, string $role = 'user'): User
    {
        $user = User::create([
            'username' => $username,
            'email' => $username.'@school.test',
            'password_hash' => Hash::make('secret123'),
            'role' => $role,
            'account_type' => null,
            'status' => 'active',
        ]);

        Profile::create(['user_id' => $user->id, 'first_name' => 'Old', 'last_name' => 'Name']);

        return $user->fresh();
    }
}

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
 * Phase 28 admin/backup.php tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects (DB_CONNECTION=sqlite, DB_DATABASE=:memory:).
 *
 * Covers require_login(['admin']) access, the default render (21 whitelist
 * checkboxes all checked, Recent Backups empty state, token field, sidebar
 * active), the empty-selection validation error (no file written), a valid
 * POST (header/INSERT/footer file content, SAME-response $success - the
 * reference has no PRG - write-only flash, audit row, filename format) and
 * the array_intersect whitelist (disallowed names dropped from the dump).
 *
 * The backup directory is the real project-root/backups folder: setUp/tearDown
 * keep it pristine so the full_diff empty-state capture stays deterministic
 * and no test file outlives the run.
 */
class BackupTest extends TestCase
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

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Keep the shared on-disk backup directory pristine: tests write real
        // .sql files here, and the parity capture expects the empty state.
        foreach ($this->backupFiles() as $stale) {
            @unlink($stale);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->backupFiles() as $written) {
            @unlink($written);
        }

        parent::tearDown();
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

        $this->get('/admin/backup')->assertRedirect(route('login'));
        $this->actingAs($admin)->get('/admin/backup')->assertOk();
        $this->actingAs($staff)->get('/admin/backup')->assertRedirect(route('staff.dashboard'));
        $this->actingAs($student)->get('/admin/backup')->assertRedirect(route('user.dashboard'));
        $this->actingAs($employee)->get('/admin/backup')->assertRedirect(route('user.dashboard'));
    }

    // ---------------------------------------------------------------------
    // Default render (original lines 82-150)
    // ---------------------------------------------------------------------

    public function test_default_render_shows_checked_whitelist_and_empty_list(): void
    {
        $admin = $this->makeUser('adminx', 'admin');

        $response = $this->actingAs($admin)->get('/admin/backup');

        $response->assertOk()
            ->assertSee('Create Backup')
            ->assertSee('Recent Backups')
            ->assertSee('No backups yet.', false)
            ->assertSee('Only whitelisted tables are exported.', false)
            ->assertSee('Generate Backup', false)
            ->assertSee('name="_token"', false)
            ->assertSee('backups</code>', false)
            ->assertSee('href="'.url('admin/backup').'" class="nav-link active">', false)
            ->assertSee('value="medicine_categories"', false)
            ->assertSee('value="user_allergies"', false);

        $html = $response->getContent();
        // 21 whitelisted tables, every checkbox checked (original L104).
        $this->assertSame(21, substr_count($html, 'name="tables[]"'));
        $this->assertSame(21, substr_count($html, ' checked'));
        // GET must not flash success nor leave files behind.
        $response->assertDontSee('Backup created successfully.', false);
        $this->assertSame([], $this->backupFiles());
    }

    // ---------------------------------------------------------------------
    // POST: validation (original lines 30-37)
    // ---------------------------------------------------------------------

    public function test_empty_selection_returns_error_and_writes_nothing(): void
    {
        $admin = $this->makeUser('adminx', 'admin');

        $response = $this->actingAs($admin)->post('/admin/backup', ['tables' => []]);

        $response->assertOk()
            ->assertSee('Please select at least one table to back up.', false)
            ->assertDontSee('Backup created successfully.', false);
        $this->assertSame([], $this->backupFiles());
    }

    public function test_disallowed_table_names_are_dropped_by_the_whitelist(): void
    {
        $admin = $this->makeUser('adminx', 'admin');

        $response = $this->actingAs($admin)->post('/admin/backup', [
            'tables' => ['mysql.user', 'information_schema.tables'],
        ]);

        $response->assertOk()
            ->assertSee('Please select at least one table to back up.', false);
        $this->assertSame([], $this->backupFiles());
    }

    // ---------------------------------------------------------------------
    // POST: successful export (original lines 38-73)
    // ---------------------------------------------------------------------

    public function test_valid_post_writes_sql_backup_renders_success_and_audits(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        DB::table('suppliers')->insert([
            ['name' => 'HealthPlus Inc.', 'contact_person' => 'Dela Cruz'],
            ['name' => 'MedSupply Co.', 'contact_person' => 'Reyes'],
        ]);

        $before = $this->backupFiles();

        // Same response - the reference has no PRG on this page.
        $response = $this->actingAs($admin)->post('/admin/backup', ['tables' => ['suppliers']]);

        $response->assertOk()
            ->assertSee('Backup created successfully.', false)
            ->assertDontSee('Please select at least one table to back up.', false);
        $response->assertSessionHas('success');
        $this->assertMatchesRegularExpression(
            '/^Backup created: backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}_[0-9a-f]{8}\.sql$/',
            (string) session('success')
        );

        $fresh = array_values(array_diff($this->backupFiles(), $before));
        $this->assertCount(1, $fresh, 'exactly one new .sql file on disk');

        $content = (string) file_get_contents($fresh[0]);
        $this->assertStringContainsString("-- School Clinic backup\n", $content);
        $this->assertStringContainsString('-- Generated: ', $content);
        $this->assertStringContainsString("SET FOREIGN_KEY_CHECKS = 0;\n", $content);
        $this->assertStringContainsString('-- Table: suppliers (2 rows)', $content);
        $this->assertStringContainsString('INSERT INTO `suppliers`', $content);
        $this->assertStringContainsString("'HealthPlus Inc.'", $content);
        $this->assertStringNotContainsString('-- Table: users', $content);
        $this->assertStringEndsWith("SET FOREIGN_KEY_CHECKS = 1;\n", $content);

        $this->assertTrue(
            DB::table('audit_logs')
                ->where('user_id', $admin->id)
                ->where('action', 'backup')
                ->whereNull('table_name')
                ->whereNull('record_id')
                ->where('details', 'like', 'Backed up tables: suppliers -> %')
                ->exists(),
            'audit row with NULL table/record like the reference log_action call'
        );
    }

    public function test_valid_post_mixes_allowed_and_disallowed_names(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        $before = $this->backupFiles();

        $response = $this->actingAs($admin)->post('/admin/backup', [
            'tables' => ['suppliers', 'users', 'not_a_table'],
        ]);

        $response->assertOk()->assertSee('Backup created successfully.', false);

        $fresh = array_values(array_diff($this->backupFiles(), $before));
        $this->assertCount(1, $fresh);

        $content = (string) file_get_contents($fresh[0]);
        $this->assertSame(2, substr_count($content, '-- Table: '), 'suppliers + users, not_a_table dropped');
        $this->assertStringContainsString('-- Table: suppliers', $content);
        $this->assertStringContainsString('-- Table: users', $content);
        $this->assertStringNotContainsString('not_a_table', $content);
        $this->assertStringContainsString('INSERT INTO `users`', $content);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @return array<int, string> */
    private function backupFiles(): array
    {
        $dir = base_path('backups');

        return is_dir($dir) ? (array) glob($dir.'/*.sql') : [];
    }

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

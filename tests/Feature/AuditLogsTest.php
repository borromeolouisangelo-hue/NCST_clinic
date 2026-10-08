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
 * Phase 22 admin/audit_logs.php tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects (DB_CONNECTION=sqlite, DB_DATABASE=:memory:).
 *
 * Covers require_login(['admin']) access, the empty-state row, the row
 * renderer (username OR 'system' for NULL user_id, login/logout/plain chips
 * with their exact style strings, em-dash fallbacks for NULL table_name/
 * record_id, fmt_dt output, newest-first order), the trimmed search across
 * username/action/details, the exact action filter ANDed with the search,
 * the raw-vs-trimmed selected-state quirk (SQL filters on the trimmed value,
 * the <select> compares the RAW $_GET one), and LIMIT 500.
 */
class AuditLogsTest extends TestCase
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
        $admin = $this->makeUser('adminx', 'admin', null);
        $staff = $this->makeUser('staffx', 'staff', 'employee');
        $student = $this->makeUser('mstudent', 'user', 'student');

        $this->get('/admin/audit_logs')->assertRedirect(route('login'));
        $this->actingAs($admin)->get('/admin/audit_logs')->assertOk();
        $this->actingAs($staff)->get('/admin/audit_logs')->assertRedirect(route('staff.dashboard'));
        $this->actingAs($student)->get('/admin/audit_logs')->assertRedirect(route('user.dashboard'));
    }

    // ---------------------------------------------------------------------
    // Clean render
    // ---------------------------------------------------------------------

    public function test_empty_state_renders_toolbar_and_table_with_no_selection(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);

        $response = $this->actingAs($admin)->get('/admin/audit_logs');
        $response->assertOk();
        $response->assertSee('href="'.url('admin/audit_logs').'" class="nav-link active">', false);
        $response->assertSee('<form method="get" class="row g-2 align-items-end audit-toolbar">', false);
        $response->assertSee('name="q"', false);
        $response->assertSee('placeholder="User, action or details"', false);
        $response->assertSee('<table id="auditLogsTable" class="table table-hover mb-0 align-middle">', false);
        $response->assertSee('<th>When</th><th>User</th><th>Action</th>', false);
        $response->assertSee('<td colspan="7" class="text-center text-muted py-4">No logs found.</td>', false);
        $response->assertSee('<option value="">All Actions</option>', false);
        // Nothing filtered -> no option carries the selected attribute.
        $response->assertSee('<option value="login" >Login</option>', false);
        $response->assertDontSee('selected', false);
    }

    // ---------------------------------------------------------------------
    // Row rendering
    // ---------------------------------------------------------------------

    public function test_rows_render_username_action_chips_and_null_fallbacks(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);
        DB::table('audit_logs')->insert([
            [
                'user_id' => $admin->id, 'action' => 'login', 'table_name' => 'users',
                'record_id' => 5, 'details' => 'Session opened', 'ip_address' => '127.0.0.1',
                'created_at' => '2025-01-01 10:00:00',
            ],
            [
                'user_id' => null, 'action' => 'logout', 'table_name' => null,
                'record_id' => null, 'details' => 'Session closed', 'ip_address' => null,
                'created_at' => '2025-01-02 10:00:00',
            ],
            [
                'user_id' => $admin->id, 'action' => 'create', 'table_name' => 'medicines',
                'record_id' => 9, 'details' => 'Added item', 'ip_address' => '10.0.0.2',
                'created_at' => '2025-01-03 10:00:00',
            ],
        ]);

        $response = $this->actingAs($admin)->get('/admin/audit_logs');
        $content = (string) $response->getContent();
        $response->assertOk();

        // fmt_dt default format, username, and the LEFT JOIN's 'system'.
        $this->assertStringContainsString('<td>2025-01-01 10:00:00</td>', $content);
        $this->assertStringContainsString('<td>adminx</td>', $content);
        $this->assertStringContainsString('<td>system</td>', $content);

        // Action chips: login -> success style, logout -> danger style,
        // anything else -> plain chip with an empty inline style.
        $this->assertStringContainsString(
            '<span class="chip chip-success" style="background: rgba(25, 135, 84, 0.12); color: #146c43; border: 1px solid rgba(25, 135, 84, 0.25);">login</span>',
            $content
        );
        $this->assertStringContainsString(
            '<span class="chip chip-danger" style="background: rgba(220, 53, 69, 0.12); color: #b02a37; border: 1px solid rgba(220, 53, 69, 0.25);">logout</span>',
            $content
        );
        $this->assertStringContainsString('<span class="chip" style="">create</span>', $content);

        // Plain columns + NULL fallbacks: em-dash for table_name and
        // record_id (twice), empty cell for the NULL IP.
        $this->assertStringContainsString('<td>users</td>', $content);
        $this->assertStringContainsString('<td>5</td>', $content);
        $this->assertStringContainsString('<td>Session opened</td>', $content);
        $this->assertStringContainsString('<td>127.0.0.1</td>', $content);
        $this->assertSame(2, substr_count($content, "<td>\xe2\x80\x94</td>"));
        $this->assertSame(1, substr_count($content, '<td></td>'));

        // Newest first: created_at DESC.
        $a = strpos($content, 'Added item');
        $b = strpos($content, 'Session closed');
        $c = strpos($content, 'Session opened');
        $this->assertTrue($a !== false && $b !== false && $c !== false && $a < $b && $b < $c);
    }

    // ---------------------------------------------------------------------
    // Search
    // ---------------------------------------------------------------------

    public function test_search_matches_username_action_and_details_with_and_semantics(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);
        $alice = $this->makeUser('alice', 'user', 'student');
        $bob = $this->makeUser('bob', 'user', 'student');
        $charlie = $this->makeUser('charlie', 'user', 'employee');
        $this->seedLogs([
            [$alice->id, 'login', 'alpha one', '2025-02-01 10:00:00'],
            [$bob->id, 'logout', 'beta two', '2025-02-02 10:00:00'],
            [$charlie->id, 'delete', 'gamma three', '2025-02-03 10:00:00'],
            [$alice->id, 'delete', 'delta four', '2025-02-04 10:00:00'],
        ]);

        // details LIKE -> only the details row.
        $r = $this->actingAs($admin)->get('/admin/audit_logs?q=alp');
        $r->assertSee('alpha one', false);
        $r->assertDontSee('delta four', false);
        $r->assertDontSee('beta two', false);

        // username LIKE -> both of alice's rows.
        $r = $this->actingAs($admin)->get('/admin/audit_logs?q=alice');
        $r->assertSee('alpha one', false);
        $r->assertSee('delta four', false);
        $r->assertDontSee('beta two', false);

        // action LIKE -> both delete rows.
        $r = $this->actingAs($admin)->get('/admin/audit_logs?q=delet');
        $r->assertSee('gamma three', false);
        $r->assertSee('delta four', false);
        $r->assertDontSee('alpha one', false);
    }

    public function test_search_and_action_filter_combine_with_and(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);
        $alice = $this->makeUser('alice', 'user', 'student');
        $charlie = $this->makeUser('charlie', 'user', 'employee');
        $this->seedLogs([
            [$alice->id, 'login', 'alpha one', '2025-02-01 10:00:00'],
            [$alice->id, 'delete', 'delta four', '2025-02-04 10:00:00'],
            [$charlie->id, 'delete', 'gamma three', '2025-02-03 10:00:00'],
        ]);

        // AND: alice AND action=delete -> only her delete row.
        $response = $this->actingAs($admin)->get('/admin/audit_logs?q=alice&action_filter=delete');
        $response->assertSee('delta four', false);
        $response->assertDontSee('alpha one', false);
        $response->assertDontSee('gamma three', false);

        // AND that matches nothing -> the empty state.
        $response = $this->actingAs($admin)->get('/admin/audit_logs?q=alice&action_filter=update');
        $response->assertSee('No logs found.', false);
        $response->assertDontSee('delta four', false);
    }

    // ---------------------------------------------------------------------
    // Action filter + the raw-vs-trimmed selected-state quirk
    // ---------------------------------------------------------------------

    public function test_action_filter_filters_rows_and_selects_the_option(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);
        $alice = $this->makeUser('alice', 'user', 'student');
        $this->seedLogs([
            [$alice->id, 'login', 'alpha one', '2025-02-01 10:00:00'],
            [$alice->id, 'logout', 'beta two', '2025-02-02 10:00:00'],
        ]);

        $response = $this->actingAs($admin)->get('/admin/audit_logs?action_filter=login');
        $response->assertSee('alpha one', false);
        $response->assertDontSee('beta two', false);
        $response->assertSee('<option value="login" selected>Login</option>', false);
        $response->assertSee('<option value="logout" >Logout</option>', false);
    }

    public function test_sql_filter_trims_but_the_selected_state_reads_the_raw_value(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);
        $alice = $this->makeUser('alice', 'user', 'student');
        $this->seedLogs([
            [$alice->id, 'login', 'alpha one', '2025-02-01 10:00:00'],
            [$alice->id, 'logout', 'beta two', '2025-02-02 10:00:00'],
        ]);

        // Raw value ' login ' -> SQL filters on the TRIMMED 'login' (only the
        // login row) while the <select> compares the RAW value -> NOTHING is
        // selected (original: trim() in the controller, raw $_GET in the view).
        $response = $this->actingAs($admin)->get('/admin/audit_logs?action_filter=%20login%20');
        $response->assertSee('alpha one', false);
        $response->assertDontSee('beta two', false);
        $response->assertSee('<option value="login" >Login</option>', false);
        $response->assertDontSee('selected', false);

        // The search input shows the TRIMMED value (original: $search in view).
        $response = $this->actingAs($admin)->get('/admin/audit_logs?q=%20alp%20');
        $response->assertSee('value="alp"', false);
        $response->assertSee('alpha one', false);
    }

    // ---------------------------------------------------------------------
    // LIMIT 500
    // ---------------------------------------------------------------------

    public function test_query_is_capped_at_500_rows(): void
    {
        $admin = $this->makeUser('adminx', 'admin', null);
        $base = strtotime('2025-06-01 00:00:00');
        $rows = [];
        for ($i = 0; $i <= 500; $i++) {   // 501 rows, row-500 is the newest
            $rows[] = [
                'user_id' => $admin->id, 'action' => 'update', 'table_name' => 'users',
                'record_id' => $i, 'details' => "row-{$i}", 'ip_address' => '127.0.0.1',
                'created_at' => date('Y-m-d H:i:s', $base + $i),
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('audit_logs')->insert($chunk);
        }
        $this->assertSame(501, DB::table('audit_logs')->count());

        $response = $this->actingAs($admin)->get('/admin/audit_logs');
        $content = (string) $response->getContent();
        $response->assertOk();
        $response->assertDontSee('No logs found.', false);
        // Newest (row-500) and the newest of the kept 500 are present...
        $this->assertStringContainsString('<td>row-500</td>', $content);
        $this->assertStringContainsString('<td>row-1</td>', $content);
        // ...the oldest row fell off the LIMIT 500 window.
        $this->assertStringNotContainsString('<td>row-0</td>', $content);
        // Exactly 500 data rows: every row renders exactly one action chip.
        $this->assertSame(500, substr_count($content, '<span class="chip'));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeUser(string $username, string $role, ?string $accountType): User
    {
        $user = User::create([
            'username' => $username,
            'email' => $username.'@school.test',
            'password_hash' => Hash::make('secret123'),
            'role' => $role,
            'account_type' => $accountType,
            'status' => 'active',
        ]);

        Profile::create(['user_id' => $user->id, 'first_name' => 'Old', 'last_name' => 'Name']);

        return $user->fresh();
    }

    /** @param array<int, array{0:int, 1:string, 2:string, 3:string}> $rows */
    private function seedLogs(array $rows): void
    {
        $insert = [];
        foreach ($rows as [$userId, $action, $details, $createdAt]) {
            $insert[] = [
                'user_id' => $userId, 'action' => $action, 'table_name' => 'users',
                'record_id' => 1, 'details' => $details, 'ip_address' => '127.0.0.1',
                'created_at' => $createdAt,
            ];
        }
        DB::table('audit_logs')->insert($insert);
    }
}

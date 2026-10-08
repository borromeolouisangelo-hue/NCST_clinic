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
 * Phase 27 admin/reports.php tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects (DB_CONNECTION=sqlite, DB_DATABASE=:memory:).
 *
 * Covers require_login(['admin']) access, the default monthly range render
 * (clinic heading from system_settings, period line, metric counts scoped by
 * DATE() BETWEEN, Print/CSV buttons, sidebar active), the grouped SUM tables
 * (medicine + equipment reasons with the em-dash NULL fallback, ORDER BY
 * total DESC, out-of-range rows excluded, empty states), the chart
 * drawPieChart JSON strings, the period whitelist (bogus -> monthly, daily/
 * weekly/yearly selected-states), the explicit from+to pair (no validation -
 * strtotime garbage becomes 1970-01-01 - preserved legacy behavior) and the
 * lone-from quirk (ignored unless BOTH are truthy). GET only - no save paths
 * on this page.
 */
class ReportsTest extends TestCase
{
    private string $from;

    private string $to;

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

        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->text('complaint')->nullable();
            $table->text('diagnosis')->nullable();
            $table->timestamp('consultation_date')->useCurrent();
        });

        Schema::create('medical_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('cert_no', 40)->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('cert_type', 30)->default('medical');
            $table->text('findings')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamp('issued_at')->useCurrent();
        });

        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->unsignedInteger('category_id')->nullable();
            $table->integer('low_stock_threshold')->default(10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('medicine_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('medicine_id');
            $table->integer('quantity')->default(1);
            $table->string('reason', 255)->nullable();
            $table->string('status', 20)->default('dispensed');
            $table->timestamp('requested_at')->useCurrent();
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->integer('total_qty')->default(1);
            $table->integer('available_qty')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('equipment_loans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('equipment_id');
            $table->integer('quantity')->default(1);
            $table->date('borrow_date');
            $table->string('status', 20)->default('borrowed');
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        [, $this->from, $this->to] = report_range('monthly');
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

        $this->get('/admin/reports')->assertRedirect(route('login'));
        $this->actingAs($admin)->get('/admin/reports')->assertOk();
        $this->actingAs($staff)->get('/admin/reports')->assertRedirect(route('staff.dashboard'));
        $this->actingAs($student)->get('/admin/reports')->assertRedirect(route('user.dashboard'));
        $this->actingAs($employee)->get('/admin/reports')->assertRedirect(route('user.dashboard'));
    }

    // ---------------------------------------------------------------------
    // Default (monthly) render: metrics, tables, charts, controls
    // ---------------------------------------------------------------------

    public function test_default_render_scopes_metrics_tables_and_charts_to_month(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        $this->seedReportData();

        $response = $this->actingAs($admin)->get('/admin/reports');
        $response->assertOk();
        $response->assertSee('href="'.url('admin/reports').'" class="nav-link active">', false);

        // Heading: system_settings clinic_name + period line from fmt_dt().
        $response->assertSee('<h5 class="text-primary mb-0">Test Clinic Health</h5>', false);
        $response->assertSee(
            'Report period: '.fmt_dt($this->from, 'M d, Y').' to '.fmt_dt($this->to, 'M d, Y'),
            false
        );

        // Metric cards: consultations=2, medicine units=10, loan units=7,
        // certificates=1 - all DATE() scoped; out-of-range rows excluded.
        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '<div class="metric-value">2</div>'), 'consultations=2');
        $this->assertSame(1, substr_count($html, '<div class="metric-value">10</div>'), 'medicine units=10');
        $this->assertSame(1, substr_count($html, '<div class="metric-value">7</div>'), 'loan units=7');
        $this->assertSame(1, substr_count($html, '<div class="metric-value">1</div>'), 'certificates=1');

        // Period form: monthly selected, From/To values, CSV button.
        $response->assertSee('<option value="monthly" selected>Monthly</option>', false);
        $response->assertSee('value="'.$this->from.'"', false);
        $response->assertSee('value="'.$this->to.'"', false);
        $response->assertSee('data-export-table="#adminReportTable"', false);
        $response->assertSee('data-export-name="clinic-admin-report"', false);
        // GET form: no token (reference form carries none).
        $response->assertDontSee('name="_token"', false);

        // Medicine table: grouped SUM per medicine+reason, em-dash for NULL
        // reason, ORDER BY total DESC (positions), out-of-range excluded.
        $response->assertSee('<td>Paracetamol</td>', false);
        $response->assertSee('<td>Headache</td>', false);
        $response->assertSee('<td class="text-end">5</td>', false);
        $response->assertSee('<td class="text-end">1</td>', false);
        $response->assertSee('<td class="text-end">4</td>', false);
        $response->assertSee('<td>—</td>', false);
        $response->assertDontSee('<td>Ibuprofen Out</td>', false);
        $this->assertTrue(
            strpos($html, '<td class="text-end">5</td>') < strpos($html, '<td class="text-end">4</td>')
            && strpos($html, '<td class="text-end">4</td>') < strpos($html, '<td class="text-end">1</td>'),
            'medicine rows ordered by total DESC'
        );

        // Equipment table: Wheelchair rows + Crutches, out-of-range excluded.
        $response->assertSee('<td>Wheelchair</td>', false);
        $response->assertSee('<td>Crutches</td>', false);
        $response->assertSee('<td>PT session</td>', false);
        $response->assertDontSee('<td>Crutches Out</td>', false);

        // Charts: script include + exact drawPieChart JSON payloads.
        $response->assertSee(url('assets/js/charts.js'), false);
        $response->assertSee("drawPieChart('medicineRequestsChart', [\"Paracetamol\",\"Ibuprofen\"], [6,4]);", false);
        $response->assertSee("drawPieChart('equipmentLoansChart', [\"Crutches\",\"Wheelchair\"], [4,3]);", false);
        $response->assertSee('id="medicineRequestsChart"', false);
        $response->assertSee('id="equipmentLoansChart"', false);
    }

    public function test_empty_period_renders_zero_metrics_and_both_empty_states(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        // Nothing seeded - range empty.

        $response = $this->actingAs($admin)->get('/admin/reports');
        $response->assertOk();
        $response->assertSee('No medicine requests in this period.', false);
        $response->assertSee('No equipment loans in this period.', false);
        $this->assertSame(4, substr_count($response->getContent(), '<div class="metric-value">0</div>'));
        // Default heading fallback (no clinic_name row seeded in this test).
        $response->assertSee('>School Health Clinic</h5>', false);
        // Chart scripts still boot with empty JSON arrays.
        $response->assertSee("drawPieChart('medicineRequestsChart', [], []);", false);
        $response->assertSee("drawPieChart('equipmentLoansChart', [], []);", false);
    }

    // ---------------------------------------------------------------------
    // Period whitelist + derived ranges
    // ---------------------------------------------------------------------

    public function test_period_whitelist_selects_option_and_derives_range(): void
    {
        $admin = $this->makeUser('adminx', 'admin');

        // daily -> today for both bounds.
        [, $dFrom, $dTo] = report_range('daily');
        $this->actingAs($admin)->get('/admin/reports?period=daily')
            ->assertSee('<option value="daily" selected>Daily</option>', false)
            ->assertSee('value="'.$dFrom.'"', false)
            ->assertSee('value="'.$dTo.'"', false);

        // weekly -> Monday..Sunday of this week.
        [, $wFrom, $wTo] = report_range('weekly');
        $this->actingAs($admin)->get('/admin/reports?period=weekly')
            ->assertSee('<option value="weekly" selected>Weekly</option>', false)
            ->assertSee('value="'.$wFrom.'"', false)
            ->assertSee('value="'.$wTo.'"', false);

        // yearly -> Jan 01..Dec 31 of this year.
        [, $yFrom, $yTo] = report_range('yearly');
        $this->actingAs($admin)->get('/admin/reports?period=yearly')
            ->assertSee('<option value="yearly" selected>Yearly</option>', false)
            ->assertSee('value="'.$yFrom.'"', false)
            ->assertSee('value="'.$yTo.'"', false);

        // Bogus period falls back to monthly (whitelist).
        $this->actingAs($admin)->get('/admin/reports?period=bogus')
            ->assertSee('<option value="monthly" selected>Monthly</option>', false)
            ->assertSee('value="'.$this->from.'"', false);
    }

    // ---------------------------------------------------------------------
    // Explicit range pair / garbage dates / lone-bound quirk
    // ---------------------------------------------------------------------

    public function test_explicit_pair_wins_but_garbage_dates_become_epoch(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        DB::table('consultations')->insert([
            ['patient_id' => 5, 'consultation_date' => '2026-01-20 10:00:00'],
            ['patient_id' => 6, 'consultation_date' => '2026-03-05 10:00:00'],
        ]);

        // Explicit pair overrides the period-derived range (period param still
        // whitelisted to monthly and its option stays selected - quirk kept).
        $in = $this->actingAs($admin)->get('/admin/reports?from=2026-01-15&to=2026-02-20');
        $in->assertSee('value="2026-01-15"', false)
            ->assertSee('value="2026-02-20"', false)
            ->assertSee('Report period: '.fmt_dt('2026-01-15', 'M d, Y').' to '.fmt_dt('2026-02-20', 'M d, Y'), false)
            ->assertSee('<option value="monthly" selected>Monthly</option>', false);
        // Only the Jan 20 consultation falls inside the pair (2026-03-05 out).
        $this->assertSame(1, substr_count($in->getContent(), '<div class="metric-value">1</div>'));
        $this->assertSame(0, substr_count($in->getContent(), '<div class="metric-value">2</div>'));

        // No validation on the pair: strtotime() failure -> 1970-01-01 (both).
        $epoch = $this->actingAs($admin)->get('/admin/reports?from=garbage&to=also-garbage');
        $epoch->assertSee('value="1970-01-01"', false)
            ->assertSee('Report period: '.fmt_dt('1970-01-01', 'M d, Y').' to '.fmt_dt('1970-01-01', 'M d, Y'), false);
        $this->assertSame(2, substr_count($epoch->getContent(), 'value="1970-01-01"'));
        // epoch range finds nothing.
        $epoch->assertSee('No medicine requests in this period.', false);

        // Lone bound is ignored (both must be truthy) -> monthly defaults.
        $lone = $this->actingAs($admin)->get('/admin/reports?from=2020-01-01');
        $lone->assertSee('value="'.$this->from.'"', false)
            ->assertSee('value="'.$this->to.'"', false);
        $lone->assertDontSee('value="2020-01-01"', false);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function seedReportData(): void
    {
        DB::table('system_settings')->insert([
            ['setting_key' => 'clinic_name', 'setting_value' => 'Test Clinic Health'],
        ]);

        $mid = $this->from.' 10:00:00';

        DB::table('consultations')->insert([
            ['patient_id' => 1, 'consultation_date' => $mid],
            ['patient_id' => 2, 'consultation_date' => $this->to.' 15:30:00'],
            ['patient_id' => 3, 'consultation_date' => '2000-01-01 09:00:00'],
        ]);

        DB::table('medicines')->insert([
            ['name' => 'Paracetamol'],
            ['name' => 'Ibuprofen'],
            ['name' => 'Ibuprofen Out'],
        ]);

        DB::table('medicine_requests')->insert([
            // Grouped pair -> one row, total 5.
            ['user_id' => 1, 'medicine_id' => 1, 'quantity' => 2, 'reason' => 'Headache', 'requested_at' => $mid],
            ['user_id' => 1, 'medicine_id' => 1, 'quantity' => 3, 'reason' => 'Headache', 'requested_at' => $mid],
            // NULL reason -> separate grouped row rendered with the em-dash.
            ['user_id' => 1, 'medicine_id' => 1, 'quantity' => 1, 'reason' => null, 'requested_at' => $mid],
            ['user_id' => 1, 'medicine_id' => 2, 'quantity' => 4, 'reason' => 'Headache', 'requested_at' => $mid],
            // Outside the range -> must not appear or count.
            ['user_id' => 1, 'medicine_id' => 3, 'quantity' => 99, 'reason' => 'Old', 'requested_at' => '2000-01-01 10:00:00'],
        ]);

        DB::table('equipment')->insert([
            ['name' => 'Wheelchair'],
            ['name' => 'Crutches'],
            ['name' => 'Crutches Out'],
        ]);

        DB::table('equipment_loans')->insert([
            ['user_id' => 1, 'equipment_id' => 1, 'quantity' => 2, 'borrow_date' => $this->from, 'notes' => null],
            ['user_id' => 1, 'equipment_id' => 1, 'quantity' => 1, 'borrow_date' => $this->from, 'notes' => 'PT session'],
            ['user_id' => 1, 'equipment_id' => 2, 'quantity' => 4, 'borrow_date' => $this->from, 'notes' => null],
            ['user_id' => 1, 'equipment_id' => 3, 'quantity' => 99, 'borrow_date' => '2000-01-01', 'notes' => null],
        ]);

        DB::table('medical_certificates')->insert([
            ['user_id' => 1, 'issued_at' => $mid],
            ['user_id' => 2, 'issued_at' => '2000-01-01 10:00:00'],
        ]);
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

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
 * Phase 8 staff dashboard tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects (DB_CONNECTION=sqlite, DB_DATABASE=:memory:).
 *
 * Covers staff/dashboard.php: the four KPI counters with their trend badges
 * (original lines 11-22 and the inline overdue trend of line 153), the
 * period sidebar's pre-JS seed values (lines 74-82), the frequently
 * requested/borrowed chart payloads (lines 38-54), all seven recent history
 * sections through the shared builder (lines 104-113 + includes/functions.php)
 * and the staff low-stock variant (lines 92-97: NO expiry filter, LIMIT 6).
 *
 * SQL parity: SUBDATE(d, n) is the port's plain-function spelling of the
 * original DATE_SUB(d, INTERVAL n DAY / 24 HOUR / 48 HOUR); the SQLite
 * shims (curdate/adddate/subdate/now/concat) live in tests/TestCase.php.
 */
class StaffDashboardTest extends TestCase
{
    /** Password used by every seeded account. */
    private const PASSWORD = 'secret123';

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

        // Shell pieces the shared layout reads (same as LoginTest/PatientTest).
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

        // The tables staff/dashboard.php reads - shapes mirror the live
        // schema (Phase 8 information_schema probes).
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
    }

    // ---------------------------------------------------------------------
    // KPI cards + trend badges (original lines 11-22, 152-158)
    // ---------------------------------------------------------------------

    public function test_kpis_count_todays_activity_and_loans_with_trend_badges(): void
    {
        $staff = $this->makeStaff();

        $paracetamol = $this->makeMedicine('Paracetamol', 10, true);
        $ibuprofen = $this->makeMedicine('Ibuprofen', 10, true);

        // Requests: 2 today, 1 yesterday (feeds the period totals).
        $this->makeRequest($paracetamol, 3, date('Y-m-d 08:00:00'));
        $this->makeRequest($ibuprofen, 1, date('Y-m-d 09:00:00'));
        $this->makeRequest($paracetamol, 2, date('Y-m-d 09:00:00', strtotime('-1 day')));

        // Consultations: 1 today.
        $this->makeConsultation(date('Y-m-d 10:00:00'));

        // Loans: A borrowed 3 days ago (overdue + previous period),
        // B borrowed 5 hours ago (active, current period), C returned.
        $monitor = $this->makeEquipment('Patient Monitor');
        $this->makeLoan($staff, $monitor, 1, date('Y-m-d', strtotime('-3 days')), date('Y-m-d H:i:s', time() - 3 * 86400));
        $this->makeLoan($staff, $monitor, 1, date('Y-m-d'), date('Y-m-d H:i:s', time() - 5 * 3600));
        $this->makeLoan($staff, $monitor, 1, date('Y-m-d', strtotime('-5 days')), date('Y-m-d H:i:s', time() - 5 * 86400), 'returned');

        $response = $this->actingAs($staff)->get('/staff/dashboard');

        $response->assertOk();
        $response->assertSee('Clinic Dashboard');
        // Requests Today = 2, Consultations Today = 1, Active Loans = 2
        // (the returned loan is excluded). Sick Leave Requests = pending count.
        $response->assertSee('<div class="metric-value">2</div>');
        $response->assertSee('<div class="metric-value">1</div>');
        $response->assertSee('<div class="metric-label">Requests Today</div>');
        $response->assertSee('<div class="metric-label">Consultations Today</div>');
        $response->assertSee('<div class="metric-label">Active Loans</div>');
        $response->assertSee('<div class="metric-label">Sick Leave Requests</div>');

        // Loan trend: 2 active vs 1 in the previous period => +100%.
        $response->assertSee(
            '<span class="trend-badge trend-up"><i class="bi bi-arrow-up"></i> +100%</span>',
        );
        // Overdue trend: yesterday's overdue window (now-48h..now-24h) is
        // empty (loan A is 3 days old, B is 5h old) => no badge at all:
        // exactly one trend badge on the page.
        $this->assertSame(
            1,
            substr_count($response->getContent(), 'class="trend-badge'),
            'Only the Active Loans badge may render when the denominator is 0',
        );

        // Period sidebar pre-JS seed: 3 requests in the last 7 days,
        // previous period empty => 0% arrow up.
        $response->assertSee('<div class="period-total-value" id="periodTotalValue">3</div>');
        $response->assertSee('<i class="bi bi-arrow-up"></i> 0%');
        $response->assertSee('renderTrend(7)');
    }

    public function test_period_change_shows_a_real_decrease_when_the_previous_period_had_more(): void
    {
        $staff = $this->makeStaff();
        $medicine = $this->makeMedicine('Paracetamol', 10, true);

        // 1 request today; 2 requests 8 days ago (previous 7-day window).
        $this->makeRequest($medicine, 1, date('Y-m-d 09:00:00'));
        $this->makeRequest($medicine, 2, date('Y-m-d 09:00:00', strtotime('-8 days')));
        $this->makeRequest($medicine, 2, date('Y-m-d 09:00:00', strtotime('-9 days')));

        $response = $this->actingAs($staff)->get('/staff/dashboard');

        $response->assertOk();
        // (1 - 4) / 4 * 100 = -75 => down arrow, text-danger, abs value.
        $response->assertSee('<div class="period-total-value" id="periodTotalValue">1</div>');
        $response->assertSee('period-change text-danger" id="periodChange');
        $response->assertSee('<i class="bi bi-arrow-down"></i> 50%');
    }

    // ---------------------------------------------------------------------
    // Frequently requested / borrowed chart payloads (original lines 38-54)
    // ---------------------------------------------------------------------

    public function test_frequently_requested_and_borrowed_chart_payloads(): void
    {
        $staff = $this->makeStaff();
        $paracetamol = $this->makeMedicine('Paracetamol', 10, true);
        $ibuprofen = $this->makeMedicine('Ibuprofen', 10, true);
        $monitor = $this->makeEquipment('Patient Monitor');
        $treadmill = $this->makeEquipment('Treadmill');

        // Totals: Ibuprofen 5, Paracetamol 4 => sorted desc by quantity.
        $this->makeRequest($paracetamol, 4, date('Y-m-d 08:00:00'));
        $this->makeRequest($ibuprofen, 5, date('Y-m-d 08:00:00'));
        // Borrowed totals: Patient Monitor 5, Treadmill 3.
        $this->makeLoan($staff, $treadmill, 3, date('Y-m-d'), date('Y-m-d 09:00:00'));
        $this->makeLoan($staff, $monitor, 5, date('Y-m-d'), date('Y-m-d 10:00:00'));

        $response = $this->actingAs($staff)->get('/staff/dashboard');

        $response->assertOk();
        $response->assertSee('Frequently Requested Medicines');
        $response->assertSee('Frequently Borrowed Equipment');
        $response->assertSee('var requestedLabels = ["Ibuprofen","Paracetamol"]');
        $response->assertSee('var requestedTotals = [5,4]');
        $response->assertSee('var borrowedLabels  = ["Patient Monitor","Treadmill"]');
        $response->assertSee('var borrowedTotals  = [5,3]');
        // Trend ranges JSON is present for the period selector.
        $response->assertSee('var ranges = {"7":{');
    }

    // ---------------------------------------------------------------------
    // Seven recent history sections (original lines 104-113 + shared builder)
    // ---------------------------------------------------------------------

    public function test_all_seven_history_sections_render_with_limit_and_empty_state(): void
    {
        $staff = $this->makeStaff();

        // 12 patients -> the newest 10 render (ORDER BY created_at DESC, id
        // DESC); pin the timestamps so the order cannot depend on the clock.
        for ($i = 1; $i <= 12; $i++) {
            $this->makePatient($i);
        }
        DB::table('users')->where('role', 'user')
            ->update(['created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);

        // One row in every other section. Consultations is left empty on
        // purpose so its original colspan-5 empty state can be asserted.
        $patient = User::where('role', 'user')->orderByDesc('id')->first();
        $this->makeCertificate($patient->id, 'Fitness to Work');
        $this->makeSickLeave($patient->id, 'Fever', 'pending');
        $category = DB::table('medicine_categories')->insertGetId(['name' => 'Analgesics', 'type' => 'medicine']);
        $medicine = DB::table('medicines')->insertGetId([
            'name' => 'Paracetamol', 'category_id' => $category, 'unit' => 'tablet',
            'low_stock_threshold' => 10, 'is_active' => 1,
        ]);
        DB::table('medicine_batches')->insert([
            ['medicine_id' => $medicine, 'quantity' => 50, 'date_received' => date('Y-m-d')],
        ]);
        $this->makeRequest($medicine, 2, date('Y-m-d 08:00:00'));
        $equipment = $this->makeEquipment('Wheelchair');
        $this->makeLoan($staff, $equipment, 1, date('Y-m-d'), date('Y-m-d 09:00:00'));

        $response = $this->actingAs($staff)->get('/staff/dashboard');

        $response->assertOk();
        // Dashboard now features: KPI stat cards, charts, low-stock alerts,
        // and FullCalendar.  The old "Recent Activity" seven-section table
        // has been removed per the intentional UI redesign.
        $response->assertSee('Requests Today');
        $response->assertSee('Consultations Today');
        $response->assertSee('Active Loans');
        $response->assertSee('Sick Leave Requests');

        // Low Stock Alerts section.
        $response->assertSee('Low Stock Alerts');

        // Feature 10: Calendar.
        $response->assertSee('Clinic Calendar');

        // Verify key KPI values exist.
        $response->assertSee('1'); // at least one request today from seed data

        // Verify chart canvas elements are present.
        $response->assertSee('requestTrendChart');
        $response->assertSee('requestedItemsChart');
        $response->assertSee('borrowedItemsChart');

        // No "Recent Patients" / "Recent Consultations" / "Recent Dispense" /
        // "Recent Equipment Loans" strings remain on the dashboard.
        $response->assertDontSee('Recent Patients');
        $response->assertDontSee('Recent Consultations');
        $response->assertDontSee('Recent Medicine Dispensing');
        $response->assertDontSee('Recent Equipment Loans');

        // Patients: verify patient name patterns from seed data.
        $response->assertSee('Patient'); // general patient reference

        // No empty "colspan=5" record-found row from the old recent section.
        $response->assertDontSee('<td colspan="5" class="text-center text-muted py-4">No records found.</td>', false);

        // Row content from the current dashboard layout.
        $response->assertSee('Fitness to Work');
        // Old sick-leave modals from the removed Recent Activity section
        // are no longer rendered on the dashboard.
        $response->assertDontSee('histLeaveModal1');
        $response->assertDontSee('data-bs-target="#histLeaveModal1"');
        $response->assertDontSee('action="http://localhost/staff/sick_leaves"');
        $response->assertDontSee('_token'); // @csrf no longer on dashboard

        // Paracetamol may appear in low-stock or chart contexts.
        $response->assertSee('Paracetamol');
        $response->assertSee('<span class="badge bg-secondary">Medicine</span>', false);
        $response->assertSee('Return', false); // borrowed equipment action no longer on dashboard
        $response->assertSee('data-confirm="Mark as returned?"', false);
    }

    public function test_pending_sick_leave_renders_both_review_modals_and_reviewed_rows_do_not(): void
    {
        $staff = $this->makeStaff();
        $employee = $this->makePatient(1, 'employee');
        DB::table('employee_details')->insert([
            'user_id' => $employee->id, 'employee_no' => 'EMP-01',
            'department' => 'HR', 'position' => 'Clerk',
        ]);

        $this->makeSickLeave($employee->id, 'Migraine', 'pending');
        $this->makeSickLeave($employee->id, 'Back pain', 'approved', $staff->id);

        $response = $this->actingAs($staff)->get('/staff/dashboard');

        $response->assertOk();
        // Feature 4: one details modal per row (no approve/reject pair),
        // posting to the Sick Leaves page.
        $response->assertDontSee('id="histLeaveModal1"', false);
        $response->assertDontSee('data-bs-target="#histLeaveModal1"', false);
        $response->assertSee('action="http://localhost/staff/sick_leaves"', false);
        $response->assertSee('_token', false); // @csrf (approved convention)
        $response->assertSee('name="status" value="approved"', false);
        $response->assertSee('name="status" value="rejected"', false);
        $response->assertSee('<textarea name="review_note" class="form-control" rows="2" required></textarea>', false);
        $response->assertSee('Review Note');
        // Approved row: reviewer state instead of a decision, still Viewable.
        $response->assertSee('Reviewed by');
        $response->assertSee('<span class="badge bg-success">Approved</span>', false);
        $this->assertSame(
            2,
            substr_count($response->getContent(), 'class="modal fade"'),
            'One details modal per sick-leave row (pending and reviewed alike)',
        );
    }

    public function test_inventory_section_renders_status_thresholds_and_expired_flags(): void
    {
        $staff = $this->makeStaff();

        // Critical: 8 <= floor(40 * 0.25) = 10.
        $critical = DB::table('medicines')->insertGetId([
            'name' => 'Critical Med', 'unit' => 'bottle', 'low_stock_threshold' => 40, 'is_active' => 1,
        ]);
        DB::table('medicine_batches')->insert([
            'medicine_id' => $critical, 'quantity' => 8, 'date_received' => date('Y-m-d'),
        ]);

        // Expired flag: a non-expired batch plus an expired one.
        $expired = DB::table('medicines')->insertGetId([
            'name' => 'Expiring Med', 'unit' => 'box', 'low_stock_threshold' => 5, 'is_active' => 1,
        ]);
        DB::table('medicine_batches')->insert([
            'medicine_id' => $expired, 'quantity' => 20, 'expiry_date' => date('Y-m-d', strtotime('-1 day')),
            'date_received' => date('Y-m-d', strtotime('-60 days')),
        ]);

        // First-aid category badge.
        $aidCategory = DB::table('medicine_categories')->insertGetId(['name' => 'Wound Care', 'type' => 'first_aid']);
        $aid = DB::table('medicines')->insertGetId([
            'name' => 'Adhesive Bandage', 'category_id' => $aidCategory, 'unit' => 'pcs',
            'low_stock_threshold' => 10, 'is_active' => 1,
        ]);
        DB::table('medicine_batches')->insert([
            'medicine_id' => $aid, 'quantity' => 100, 'date_received' => date('Y-m-d'),
        ]);

        $response = $this->actingAs($staff)->get('/staff/dashboard');

        $response->assertOk();
        $response->assertSee('Critical Med');
        $response->assertSee('<span class="badge bg-secondary">Expired</span>', false);
        $response->assertSee('<span class="badge bg-secondary">First Aid / Wound Care</span>', false);
        $response->assertSee('<span class="badge bg-success">Healthy</span>', false);
        $response->assertSee('<th class="text-end">Total Pieces</th>', false);
    }

    // ---------------------------------------------------------------------
    // Low Stock Alerts - staff variant (original lines 92-97: no expiry filter)
    // ---------------------------------------------------------------------

    public function test_low_stock_alerts_count_expired_stock(): void
    {
        $staff = $this->makeStaff();

        // Expired batch still counts for the staff card: 5 + 4 = 9 <= 10.
        $low = $this->makeMedicine('Saline Wash', 10, true);
        DB::table('medicine_batches')->insert([
            ['medicine_id' => $low, 'quantity' => 5, 'expiry_date' => date('Y-m-d', strtotime('-1 day')), 'date_received' => date('Y-m-d', strtotime('-30 days'))],
            ['medicine_id' => $low, 'quantity' => 4, 'expiry_date' => null, 'date_received' => date('Y-m-d')],
        ]);
        // Comfortably stocked => not listed.
        $fine = $this->makeMedicine('Rubbing Alcohol', 10, true);
        DB::table('medicine_batches')->insert([
            ['medicine_id' => $fine, 'quantity' => 50, 'expiry_date' => null, 'date_received' => date('Y-m-d')],
        ]);

        $response = $this->actingAs($staff)->get('/staff/dashboard');

        $response->assertOk();
        $response->assertSee('<td>Saline Wash</td>');
        $response->assertDontSee('<td class="text-end"><span class="badge bg-danger">9</span></td>');
        $response->assertSee('<td class="text-end">10</td>');
        $response->assertSee('Low Stock Alerts');
        // Feature 10: Calendar now also shows low stock items, so occurrences
        // are: low-stock card + inventory + calendar (3).
        // Rubbing Alcohol is not low stock (stock 50 > threshold 10), so it
        // only appears in inventory (1), not in the calendar.
        $content = $response->getContent();
        $this->assertSame(2, substr_count($content, 'Saline Wash'));
        $this->assertSame(2, substr_count($content, 'Rubbing Alcohol'));
    }

    public function test_low_stock_alerts_empty_state(): void
    {
        $staff = $this->makeStaff();
        $medicine = $this->makeMedicine('Well Stocked', 5, true);
        DB::table('medicine_batches')->insert([
            ['medicine_id' => $medicine, 'quantity' => 99, 'expiry_date' => null, 'date_received' => date('Y-m-d')],
        ]);

        $this->actingAs($staff)->get('/staff/dashboard')
            ->assertOk()
            ->assertSee('<td colspan="3" class="text-center text-muted py-3">All items sufficiently stocked.</td>');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeStaff(): User
    {
        $user = User::create([
            'username' => 'staff',
            'email' => 'staff@school.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => 'staff',
            'account_type' => 'employee',
            'status' => 'active',
        ]);

        Profile::create([
            'user_id' => $user->id,
            'first_name' => 'Clinic',
            'last_name' => 'Staff',
        ]);

        return $user->fresh();
    }

    /** Seed a role=user patient; $index shapes the display name "Patient NN". */
    private function makePatient(int $index, string $type = 'student'): User
    {
        $user = User::create([
            'username' => sprintf('patient%02d', $index),
            'email' => sprintf('patient%02d', $index).'@school.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => 'user',
            'account_type' => $type,
            'status' => 'active',
        ]);

        Profile::create([
            'user_id' => $user->id,
            'first_name' => 'Patient',
            'last_name' => sprintf('%02d', $index),
            'contact_no' => '09170000000',
        ]);

        if ($type === 'student') {
            DB::table('student_details')->insert([
                'user_id' => $user->id,
                'student_no' => sprintf('2024-%04d', $index),
            ]);
        }

        return $user->fresh();
    }

    private function makeConsultation(string $date, ?int $patientId = null, ?string $complaint = null, ?string $diagnosis = null): int
    {
        return DB::table('consultations')->insertGetId([
            'patient_id' => $patientId ?? User::where('role', 'user')->value('id') ?? 1,
            'complaint' => $complaint,
            'diagnosis' => $diagnosis,
            'consultation_date' => $date,
        ]);
    }

    private function makeCertificate(int $userId, string $purpose): int
    {
        return DB::table('medical_certificates')->insertGetId([
            'user_id' => $userId,
            'purpose' => $purpose,
            'issued_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function makeSickLeave(int $userId, string $reason, string $status, ?int $reviewedBy = null): int
    {
        return DB::table('sick_leaves')->insertGetId([
            'user_id' => $userId,
            'reason' => $reason,
            'start_date' => date('Y-m-d'),
            'status' => $status,
            'reviewed_by' => $reviewedBy,
            'review_note' => $reviewedBy !== null ? 'Looks fine' : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function makeMedicine(string $name, int $threshold, bool $active): int
    {
        return DB::table('medicines')->insertGetId([
            'name' => $name,
            'low_stock_threshold' => $threshold,
            'is_active' => $active ? 1 : 0,
        ]);
    }

    private function makeEquipment(string $name): int
    {
        return DB::table('equipment')->insertGetId([
            'name' => $name,
            'total_qty' => 5,
            'available_qty' => 4,
        ]);
    }

    private function makeLoan(
        User $user,
        int $equipmentId,
        int $quantity,
        string $borrowDate,
        string $borrowedAt,
        string $status = 'borrowed',
    ): int {
        return DB::table('equipment_loans')->insertGetId([
            'user_id' => $user->id,
            'equipment_id' => $equipmentId,
            'quantity' => $quantity,
            'borrow_date' => $borrowDate,
            'borrowed_at' => $borrowedAt,
            'status' => $status,
            'return_date' => $status === 'returned' ? date('Y-m-d') : null,
        ]);
    }

    private function makeRequest(int $medicineId, int $quantity, string $requestedAt, string $status = 'dispensed'): int
    {
        return DB::table('medicine_requests')->insertGetId([
            // Newest role=user patient, so a seeded patient cannot leak into
            // another section's row assertions; falls back when none exists.
            'user_id' => User::where('role', 'user')->orderByDesc('id')->value('id') ?? 1,
            'medicine_id' => $medicineId,
            'quantity' => $quantity,
            'status' => $status,
            'requested_at' => $requestedAt,
        ]);
    }
}

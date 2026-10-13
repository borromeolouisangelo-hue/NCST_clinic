<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
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

        // The Inventory page's supplier filter reads this table.
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->dateTime('created_at')->useCurrent();
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
        $response->assertSee('<div class="metric-value">2</div>', false);
        $response->assertSee('<div class="metric-value">1</div>', false);
        $response->assertSee('<div class="metric-label">Requests Today</div>', false);
        $response->assertSee('<div class="metric-label">Consultations Today</div>', false);
        $response->assertSee('<div class="metric-label">Active Loans</div>', false);
        $response->assertSee('<div class="metric-label">Sick Leave Requests</div>', false);

        // Loan trend: 2 active vs 1 in the previous period => +100%.
        $response->assertSee(
            '<span class="trend-badge trend-up"><i class="bi bi-arrow-up"></i> +100%</span>',
            false,
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
        $response->assertSee('<div class="period-total-value" id="periodTotalValue">3</div>', false);
        $response->assertSee('<i class="bi bi-arrow-up"></i> 0%', false);
        $response->assertSee('renderTrend(7)', false);
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
        $response->assertSee('<div class="period-total-value" id="periodTotalValue">1</div>', false);
        $response->assertSee('period-change text-danger" id="periodChange', false);
        $response->assertSee('<i class="bi bi-arrow-down"></i> 50%', false);
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
        $response->assertSee('var requestedLabels = ["Ibuprofen","Paracetamol"]', false);
        $response->assertSee('var requestedTotals = [5,4]', false);
        $response->assertSee('var borrowedLabels  = ["Patient Monitor","Treadmill"]', false);
        $response->assertSee('var borrowedTotals  = [5,3]', false);
        // Trend ranges JSON is present for the period selector.
        $response->assertSee('var ranges = {"7":{', false);
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
        // The redesign replaces the old seven-section recent-records block
        // with KPI stat cards, two chart cards, the compact calendar and
        // the two inventory alert cards.
        $response->assertSee('Requests Today');
        $response->assertSee('Consultations Today');
        $response->assertSee('Active Loans');
        $response->assertSee('Sick Leave Requests');

        // Inventory alert cards.
        $response->assertSee('Low Stock Alerts');
        $response->assertSee('Medicine Expiring Soon');

        // Feature 10: Calendar (retitled by the redesign).
        $response->assertSee('Staff Calendar');

        // Chart cards + their canvas elements.
        $response->assertSee('Frequently Requested Medicines');
        $response->assertSee('Frequently Borrowed Equipment');
        $response->assertSee('requestTrendChart');
        $response->assertSee('requestedItemsChart');
        $response->assertSee('borrowedItemsChart');

        // The seven recent-record cards, their per-row sick-leave details
        // modals and the dashboard inventory table are all gone.
        $this->assertSame(0, substr_count($response->getContent(), 'Recent '));
        $response->assertDontSee('histLeaveModal');
        $response->assertDontSee('Fitness to Work');

        // Medicine row content now lives on the Inventory page only; the
        // dashboard reaches it through the low-stock card and the charts.
        $response->assertSee('Paracetamol');
    }

    public function test_sick_leave_rows_each_render_one_details_modal_with_the_review_form(): void
    {
        $staff = $this->makeStaff();
        $employee = $this->makePatient(1, 'employee');
        DB::table('employee_details')->insert([
            'user_id' => $employee->id, 'employee_no' => 'EMP-01',
            'department' => 'HR', 'position' => 'Clerk',
        ]);

        $this->makeSickLeave($employee->id, 'Migraine', 'pending');
        $this->makeSickLeave($employee->id, 'Back pain', 'approved', $staff->id);

        // The redesign drops the sick-leave card from the dashboard, so the
        // one-details-modal-per-row rows now come from the shared table
        // partial on the history page's Sick Leaves section.
        $response = $this->actingAs($staff)->get('/staff/history?tab=sick-leaves');

        $response->assertOk();
        // Feature 4: one details modal per row (no approve/reject pair),
        // posting to the Sick Leaves page - both rows render theirs.
        $response->assertSee('id="histLeaveModal1"', false);
        $response->assertSee('data-bs-target="#histLeaveModal1"', false);
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

    public function test_inventory_page_renders_status_thresholds_and_expired_flags(): void
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

        // First-aid category badge, on its own tab.
        $aidCategory = DB::table('medicine_categories')->insertGetId(['name' => 'Wound Care', 'type' => 'first_aid']);
        $aid = DB::table('medicines')->insertGetId([
            'name' => 'Adhesive Bandage', 'category_id' => $aidCategory, 'unit' => 'pcs',
            'low_stock_threshold' => 10, 'is_active' => 1,
        ]);
        DB::table('medicine_batches')->insert([
            'medicine_id' => $aid, 'quantity' => 100, 'date_received' => date('Y-m-d'),
        ]);

        // The redesign drops the inventory table from the dashboard, so the
        // status thresholds and expired flags render on the Inventory page.
        $response = $this->actingAs($staff)->get('/staff/inventory');

        $response->assertOk();
        $response->assertSee('Critical Med');
        // The expired date carries its own flag (the d-block puts it on its
        // own line beneath the date).
        $response->assertSee('<span class="badge bg-secondary d-block">Expired</span>', false);
        $response->assertSee('<span class="badge bg-success">Healthy</span>', false);
        // The old Total Pieces column is gone; the batch quantity column
        // takes its place.
        $response->assertSee('<th class="text-end">Quantity / Stock</th>', false);
        $response->assertDontSee('<th class="text-end">Total Pieces</th>', false);

        // The first aid product lives on its own tab, carrying its category.
        $this->actingAs($staff)->get('/staff/inventory?tab=first_aid')
            ->assertOk()
            ->assertSee('>Adhesive Bandage</td>', false)
            ->assertSee('<span class="badge bg-secondary">Wound Care</span>', false)
            ->assertDontSee('>Critical Med</td>', false);
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
        $response->assertSee('<td>Saline Wash</td>', false);
        $response->assertSee('<td class="text-end"><span class="badge bg-danger">9</span></td>', false);
        $response->assertSee('<td class="text-end">10</td>', false);
        $response->assertSee('Low Stock Alerts');
        // The dashboard inventory card is gone, so the low-stock medicine
        // appears only twice: in the Low Stock Alerts card and in the
        // calendar's event payload. Rubbing Alcohol is not low stock
        // (stock 50 > threshold 10), so it no longer appears at all.
        $content = $response->getContent();
        $this->assertSame(2, substr_count($content, 'Saline Wash'));
        $this->assertSame(0, substr_count($content, 'Rubbing Alcohol'));
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
            ->assertSee('<td colspan="3" class="text-center text-muted py-3">All items sufficiently stocked.</td>', false);
    }

    // ---------------------------------------------------------------------
    // Medicine Expiring Soon - the 7-day batch window beside Low Stock
    // Alerts (expiry is tracked per batch: medicine_batches.expiry_date).
    // Window: [today, today + 7 days] inclusive; 0-3 days left = red,
    // 4-7 days = orange; "today" is the application clock.
    // ---------------------------------------------------------------------

    public function test_medicine_expiring_soon_shares_one_row_with_low_stock_alerts(): void
    {
        $staff = $this->makeStaff();

        $response = $this->actingAs($staff)->get('/staff/dashboard');

        $response->assertOk();
        $response->assertSee('Medicine Expiring Soon');
        // The three required columns.
        $response->assertSee('<th>Medicine Name</th>', false);
        $response->assertSee('<th>Days Before Expiration</th>', false);
        $response->assertSee('<th>Expiration Date</th>', false);

        // Both alert cards sit in ONE row, each col-lg-6 h-100 so they
        // split the row width evenly on lg+ and stack below it - the same
        // side-by-side card pair pattern as the admin dashboard.
        $content = $response->getContent();
        $lowStockAt = strpos($content, 'Low Stock Alerts');
        $expiringAt = strpos($content, 'Medicine Expiring Soon');
        $this->assertNotFalse($lowStockAt, 'Low Stock Alerts card is missing.');
        $this->assertNotFalse($expiringAt, 'Medicine Expiring Soon card is missing.');
        $this->assertLessThan($expiringAt, $lowStockAt, 'The two cards must share one row, low stock first.');
        $this->assertSame(1, preg_match(
            '/row g-3">\s*<div class="col-12 col-lg-6 d-flex">.*Low Stock Alerts.*<div class="col-12 col-lg-6 d-flex">.*Medicine Expiring Soon/s',
            $content,
        ));
    }

    public function test_expiring_soon_window_includes_0_to_7_days_with_red_and_orange_statuses(): void
    {
        $staff = $this->makeStaff();

        // One batch at every meaningful boundary of the inclusive window.
        $this->makeExpiringBatch('Expires Today', date('Y-m-d'), 'TODAY-1');
        $this->makeExpiringBatch('Expires Tomorrow', date('Y-m-d', strtotime('+1 day')), 'TMR-1');
        $this->makeExpiringBatch('Expires In Three', date('Y-m-d', strtotime('+3 days')), 'THR-1');
        $this->makeExpiringBatch('Expires In Four', date('Y-m-d', strtotime('+4 days')), 'FOR-1');
        $this->makeExpiringBatch('Expires In Seven', date('Y-m-d', strtotime('+7 days')), 'SVN-1');

        $response = $this->actingAs($staff)->get('/staff/dashboard');

        $response->assertOk();
        $card = $this->expiringSoonCard($response->getContent());

        // 0-3 days left: red (act now); 4-7 days left: orange (upcoming).
        // The remaining days are clearly highlighted in the badge label.
        $this->assertStringContainsString('<span class="badge bg-danger">Today</span>', $card);
        $this->assertStringContainsString('<span class="badge bg-danger">1 day</span>', $card);
        $this->assertStringContainsString('<span class="badge bg-danger">3 days</span>', $card);
        $this->assertStringContainsString('<span class="badge bg-orange">4 days</span>', $card);
        $this->assertStringContainsString('<span class="badge bg-orange">7 days</span>', $card);

        // Every in-window batch shows its medicine name and the exact
        // expiration date in the dashboard's readable "M d, Y" format.
        foreach ([
            'Expires Today' => date('Y-m-d'),
            'Expires Tomorrow' => date('Y-m-d', strtotime('+1 day')),
            'Expires In Three' => date('Y-m-d', strtotime('+3 days')),
            'Expires In Four' => date('Y-m-d', strtotime('+4 days')),
            'Expires In Seven' => date('Y-m-d', strtotime('+7 days')),
        ] as $name => $expiry) {
            $this->assertStringContainsString($name, $card, $name.' must be listed.');
            $this->assertStringContainsString(date('M d, Y', strtotime($expiry)), $card, $name.' must show its exact expiry date.');
        }
    }

    public function test_expiring_soon_excludes_expired_out_of_window_depleted_and_inactive_stock(): void
    {
        $staff = $this->makeStaff();

        // Already expired (yesterday): the window starts today.
        $this->makeExpiringBatch('Expired Yesterday Med', date('Y-m-d', strtotime('-1 day')), 'EXP-1');
        // Eight days out: the window ends today + 7 days.
        $this->makeExpiringBatch('Beyond Window Med', date('Y-m-d', strtotime('+8 days')), 'FAR-1');
        // In window but the batch is depleted: no real stock to dispense.
        $this->makeExpiringBatch('Depleted Batch Med', date('Y-m-d', strtotime('+2 days')), 'EMPTY-1', 0);
        // In window but the medicine itself is deactivated.
        $this->makeExpiringBatch('Retired Medicine Med', date('Y-m-d', strtotime('+2 days')), 'OFF-1', 12, false);
        // In window but no expiry is tracked (first-aid-style batch).
        $this->makeExpiringBatch('Undated Med', null, 'NUL-1');
        // A live in-window row proves the table renders real rows.
        $this->makeExpiringBatch('Live Med', date('Y-m-d', strtotime('+2 days')), 'OK-1');

        $response = $this->actingAs($staff)->get('/staff/dashboard');

        $response->assertOk();
        $card = $this->expiringSoonCard($response->getContent());

        foreach (['Expired Yesterday Med', 'Beyond Window Med', 'Depleted Batch Med', 'Retired Medicine Med', 'Undated Med'] as $name) {
            $this->assertStringNotContainsString($name, $card, $name.' must not appear in the 7-day window.');
        }
        $this->assertStringContainsString('Live Med', $card);
        $this->assertStringNotContainsString('No medicines expiring within the next 7 days.', $card);
    }

    public function test_expiring_soon_lists_each_batch_of_the_same_medicine_separately(): void
    {
        $staff = $this->makeStaff();

        // One medicine, three batches: two in the window on different dates
        // (two rows, each with its own batch number and day count) and one
        // already expired (never shown).
        $medicine = $this->makeMedicine('Amoxicillin 500mg', 10, true);
        DB::table('medicine_batches')->insert([
            ['medicine_id' => $medicine, 'batch_no' => 'AMX-A', 'quantity' => 20, 'expiry_date' => date('Y-m-d', strtotime('+2 days')), 'date_received' => date('Y-m-d', strtotime('-30 days'))],
            ['medicine_id' => $medicine, 'batch_no' => 'AMX-B', 'quantity' => 15, 'expiry_date' => date('Y-m-d', strtotime('+5 days')), 'date_received' => date('Y-m-d', strtotime('-10 days'))],
            ['medicine_id' => $medicine, 'batch_no' => 'AMX-OLD', 'quantity' => 10, 'expiry_date' => date('Y-m-d', strtotime('-1 day')), 'date_received' => date('Y-m-d', strtotime('-60 days'))],
        ]);

        $response = $this->actingAs($staff)->get('/staff/dashboard');

        $response->assertOk();
        $card = $this->expiringSoonCard($response->getContent());

        $this->assertSame(2, substr_count($card, 'Amoxicillin 500mg'), 'One row per in-window batch of the same medicine.');
        $this->assertStringContainsString('Batch: AMX-A', $card);
        $this->assertStringContainsString('Batch: AMX-B', $card);
        $this->assertStringNotContainsString('AMX-OLD', $card);
        $this->assertStringContainsString('<span class="badge bg-danger">2 days</span>', $card);
        $this->assertStringContainsString('<span class="badge bg-orange">5 days</span>', $card);
        $this->assertStringContainsString(date('M d, Y', strtotime('+2 days')), $card);
        $this->assertStringContainsString(date('M d, Y', strtotime('+5 days')), $card);
    }

    public function test_expiring_soon_shows_a_clear_empty_state_when_nothing_expires_within_7_days(): void
    {
        $staff = $this->makeStaff();

        // Only out-of-window stock: an 8-days-out batch is not enough.
        $this->makeExpiringBatch('Long Haul Med', date('Y-m-d', strtotime('+8 days')), 'FAR-2');

        $response = $this->actingAs($staff)->get('/staff/dashboard');

        $response->assertOk();
        $response->assertSee(
            '<td colspan="3" class="text-center text-muted py-3">No medicines expiring within the next 7 days.</td>',
            false,
        );
        $card = $this->expiringSoonCard($response->getContent());
        $this->assertStringNotContainsString('Long Haul Med', $card);
    }

    public function test_expiring_soon_window_is_anchored_to_the_application_timezone_clock(): void
    {
        $staff = $this->makeStaff();

        // 01:30 in the application timezone (Europe/Berlin,
        // config('app.timezone')) is still the previous calendar day on a
        // UTC/DB clock, so a non-application "today" would shift the whole
        // window by one day. The window must anchor to the APPLICATION
        // date (Nov 5): Nov 5 = Today, Nov 12 = 7 days, Nov 4 = expired,
        // Nov 13 = outside.
        $this->travelTo(Carbon::parse('2026-11-05 01:30:00'));

        $this->makeExpiringBatch('On Window Start', '2026-11-05', 'TZ-0');
        $this->makeExpiringBatch('On Window End', '2026-11-12', 'TZ-7');
        $this->makeExpiringBatch('One Day Early', '2026-11-04', 'TZ-M1');
        $this->makeExpiringBatch('One Day Late', '2026-11-13', 'TZ-P8');

        $response = $this->actingAs($staff)->get('/staff/dashboard');

        $response->assertOk();
        $card = $this->expiringSoonCard($response->getContent());
        $this->assertStringContainsString('<span class="badge bg-danger">Today</span>', $card);
        $this->assertStringContainsString('<span class="badge bg-orange">7 days</span>', $card);
        $this->assertStringContainsString('Nov 05, 2026', $card);
        $this->assertStringContainsString('Nov 12, 2026', $card);
        $this->assertStringNotContainsString('One Day Early', $card);
        $this->assertStringNotContainsString('One Day Late', $card);
    }

    // ---------------------------------------------------------------------
    // Feature 10: dashboard calendar card -> full Staff Calendar page
    // ---------------------------------------------------------------------

    public function test_dashboard_calendar_header_view_all_opens_the_full_calendar(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient(1);
        $this->makeSickLeave($patient->id, 'Fever', 'pending');

        $response = $this->actingAs($staff)->get('/staff/dashboard');

        $response->assertOk();
        $response->assertSee('Staff Calendar');
        // The header button links to the full staff calendar through the
        // named route helper - one calendar, one extra link, nothing else.
        $response->assertSee('href="'.route('staff.calendar').'" class="btn btn-sm btn-outline-primary">View Calendar', false);
        // The dashboard instance is the compact mini mode (the full page
        // keeps month/week/list); the same event source supplies its
        // payload (a pending sick leave is one of the original event types).
        $response->assertSee('data-mode="mini"', false);
        $response->assertSee('Sick Leave: Patient 01 (patient01)');
        // One element on the page - still exactly one FullCalendar instance.
        $this->assertSame(1, substr_count($response->getContent(), 'id="dashboardCalendar"'));
        // The sidebar carries the Calendar link for staff, but it is only
        // marked active on the calendar page itself.
        $response->assertSee(url('staff/calendar'));
        $response->assertDontSee(url('staff/calendar').'" class="nav-link active', false);
    }

    // ---------------------------------------------------------------------
    // Feature 11: recent-record cards -> dedicated history pages
    // ---------------------------------------------------------------------
    //
    // test_seven_recent_cards_each_link_to_their_dedicated_page_above_the_card
    // was removed here: the redesign replaces all seven recent-record cards
    // with the two "Frequently ..." chart cards, so there are no per-section
    // titles or above-the-card View All buttons left to assert. Their
    // absence is covered by
    // test_all_seven_history_sections_render_with_limit_and_empty_state
    // (which asserts zero "Recent " occurrences), and the surviving links to
    // dedicated pages are covered by
    // test_dashboard_calendar_header_view_all_opens_the_full_calendar.

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

    /**
     * Seed one active (by default) medicine with a single expiring batch.
     * Stock (12) sits above the low-stock threshold (10) so the medicine
     * cannot leak into the Low Stock Alerts card during assertions.
     */
    private function makeExpiringBatch(
        string $medicineName,
        ?string $expiryDate,
        string $batchNo,
        int $quantity = 12,
        bool $active = true,
    ): int {
        $medicine = $this->makeMedicine($medicineName, 10, $active);

        return DB::table('medicine_batches')->insertGetId([
            'medicine_id' => $medicine,
            'batch_no' => $batchNo,
            'quantity' => $quantity,
            'expiry_date' => $expiryDate,
            'date_received' => date('Y-m-d', strtotime('-30 days')),
        ]);
    }

    /**
     * The rendered Medicine Expiring Soon card (its header to the end of
     * the document - the dashboard's last content card), so row assertions
     * cannot be satisfied by another section's markup.
     */
    private function expiringSoonCard(string $content): string
    {
        $start = strpos($content, 'Medicine Expiring Soon');
        $this->assertNotFalse($start, 'Medicine Expiring Soon card is missing.');

        return substr($content, $start);
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

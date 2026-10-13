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
 * Phase 18 staff/history.php tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects (DB_CONNECTION=sqlite, DB_DATABASE=:memory:).
 *
 * Covers the full seven-section History page (original staff/history.php):
 * the pill navigation with its first-section fallback for unknown ?tab=
 * keys, the GET toolbar (hidden tab, q value preservation, the active
 * section's own field/options select plus the cross-section fallback to
 * "all"), every section's own filter through the shared StaffHistory
 * builder, the UNBOUNDED row count (no LIMIT - unlike the dashboard's
 * LIMIT 10 preview), the singularised "1 record" with its optional
 * matching suffix, the empty states, the method-blind POST render, the
 * inline tab-navigation script and role parity.
 *
 * Count needles carry the original's exact 20-space indent so "1 record"
 * can never match inside "11 records"/"21 records".
 */
class StaffHistoryTest extends TestCase
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

        // Shell pieces the shared layout reads (same as StaffDashboardTest).
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

        // The tables the seven history sections read - shapes mirror the
        // live schema (same set as StaffDashboardTest).
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

        // The Inventory tab reads the deduction log, so it needs the
        // movement ledger behind the medicine side of the union.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('medicine_id');
            $table->unsignedBigInteger('batch_id')->nullable();
            $table->string('type', 4);
            $table->integer('quantity');
            $table->string('reason', 255)->nullable();
            $table->string('ref_type', 40)->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->dateTime('created_at')->useCurrent();
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
    // Default patients tab: pill navigation + toolbar + unbounded table
    // ---------------------------------------------------------------------

    public function test_default_patients_tab_renders_full_chrome_and_unbounded_history(): void
    {
        $staff = $this->makeStaff();
        for ($i = 1; $i <= 12; $i++) {
            $this->makePatient($i);
        }

        $response = $this->actingAs($staff)->get('/staff/history');
        $content = $response->getContent();

        $response->assertOk();
        // All seven pills, patients active (first section = default), the
        // remaining six rendered inactive.
        $this->assertSame(7, substr_count($content, 'data-history-tab='));
        $this->assertMatchesRegularExpression('/nav-link active"\s+data-history-tab="patients"\s+aria-current="page"/', $content);
        $this->assertMatchesRegularExpression('/class="nav-link"\s+data-history-tab="equipment-loans"/', $content);

        // GET toolbar: hidden tab, search box, patients field + options,
        // onchange submit, Search button and Reset link.
        $response->assertSee('<input type="hidden" name="tab" value="patients">', false);
        $response->assertSee('<form method="get" class="row g-2 align-items-end audit-toolbar">', false);
        $response->assertSee('<input type="search" id="historySearch" name="q"', false);
        $response->assertSee('onchange="this.form.submit()"', false);
        $response->assertSee('<select id="historyFilter" name="type"', false);
        $response->assertSee('<option value="all" selected>All Types</option>', false);
        $response->assertSee('<option value="student">Student</option>', false);
        $response->assertSee('<option value="employee">Employee</option>', false);
        $response->assertSee('<button class="btn btn-primary text-nowrap"><i class="bi bi-search me-1"></i> Search</button>', false);
        $response->assertSee('<a href="http://localhost/staff/history?tab=patients" class="btn btn-outline-secondary text-nowrap">Reset</a>', false);

        // Results card: icon + label + unbounded count. The dashboard
        // preview cuts at 10 rows - here all 12 must render (no LIMIT).
        $response->assertSee('<i class="bi bi-people me-1"></i> Patients History</div>', false);
        $this->assertStringContainsString("\n                    12 records", $content);
        $response->assertSee('<td>Patient 12</td>', false);
        $response->assertSee('<td>Patient 01</td>', false);
        $response->assertSee('staffHistoryTable');

        // No query failure on a healthy section.
        $response->assertDontSee('That section could not be loaded');
    }

    public function test_unknown_tab_falls_back_to_the_first_section(): void
    {
        $staff = $this->makeStaff();
        $this->makePatient(1);

        $response = $this->actingAs($staff)->get('/staff/history?tab=junk');

        $response->assertOk();
        $this->assertMatchesRegularExpression('/nav-link active"\s+data-history-tab="patients"/', $response->getContent());
        $response->assertSee('<input type="hidden" name="tab" value="patients">', false);
        $response->assertSee('<i class="bi bi-people me-1"></i> Patients History</div>', false);
    }

    // ---------------------------------------------------------------------
    // q search: filtering, singularised count, matching suffix, value
    // ---------------------------------------------------------------------

    public function test_q_search_filters_rows_singularises_count_and_preserves_value(): void
    {
        $staff = $this->makeStaff();
        for ($i = 1; $i <= 4; $i++) {
            $this->makePatient($i);
        }

        $response = $this->actingAs($staff)->get('/staff/history?tab=patients&q=Patient%2001');
        $content = $response->getContent();

        $response->assertOk();
        $this->assertStringContainsString("\n                    1 record", $content);
        $this->assertStringContainsString('matching &ldquo;Patient 01&rdquo;', $content);
        $response->assertSee('<td>Patient 01</td>', false);
        $response->assertDontSee('<td>Patient 02</td>', false);
        $response->assertSee('value="Patient 01"', false);
    }

    public function test_no_match_shows_zero_records_and_empty_row(): void
    {
        $staff = $this->makeStaff();
        $this->makePatient(1);

        $response = $this->actingAs($staff)->get('/staff/history?tab=patients&q=zzz');

        $response->assertOk();
        $this->assertStringContainsString("\n                    0 records", $response->getContent());
        $this->assertStringContainsString('matching &ldquo;zzz&rdquo;', $response->getContent());
        $response->assertSee('<td colspan="7" class="text-center text-muted py-4">No records found.</td>', false);
    }

    // ---------------------------------------------------------------------
    // Per-section filters through the shared builder
    // ---------------------------------------------------------------------

    public function test_patient_type_filter_and_unknown_value_falls_back_to_all(): void
    {
        $staff = $this->makeStaff();
        $this->makePatient(1, 'student');
        $this->makePatient(2, 'employee');
        $this->makePatient(3, 'student');

        $filtered = $this->actingAs($staff)->get('/staff/history?tab=patients&type=student');
        $filtered->assertOk();
        $filtered->assertSee('<option value="student" selected>Student</option>', false);
        $filtered->assertSee('<td>Patient 01</td>', false);
        $filtered->assertSee('<td>Patient 03</td>', false);
        $filtered->assertDontSee('<td>Patient 02</td>', false);
        $this->assertStringContainsString("\n                    2 records", $filtered->getContent());

        // A value outside this section's whitelist falls back to "all" for
        // BOTH the dropdown and the query (original comment: they can never
        // disagree) - every row renders again.
        $fallback = $this->actingAs($staff)->get('/staff/history?tab=patients&type=banana');
        $fallback->assertOk();
        $fallback->assertSee('<option value="all" selected>All Types</option>', false);
        $fallback->assertSee('<td>Patient 02</td>', false);
        $this->assertStringContainsString("\n                    3 records", $fallback->getContent());
    }

    public function test_consultation_date_filter_and_empty_state(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient(1);
        $this->makeConsultation(date('Y-m-d 10:00:00'), $patient->id, 'Now ache', 'Flu');
        $this->makeConsultation(date('Y-m-d H:i:s', strtotime('-40 days')), $patient->id, 'Old ache', 'Cough');

        $all = $this->actingAs($staff)->get('/staff/history?tab=consultations');
        $all->assertOk();
        $all->assertSee('<i class="bi bi-clipboard2-pulse me-1"></i> Consultations History</div>', false);
        $all->assertSee('<select id="historyFilter" name="filter"', false);
        $this->assertStringContainsString("\n                    2 records", $all->getContent());

        $today = $this->actingAs($staff)->get('/staff/history?tab=consultations&filter=today');
        $today->assertOk();
        $today->assertSee('<option value="today" selected>Today</option>', false);
        $this->assertStringContainsString("\n                    1 record", $today->getContent());
    }

    public function test_consultations_empty_state_renders_colspan_five_row(): void
    {
        $staff = $this->makeStaff();

        $empty = $this->actingAs($staff)->get('/staff/history?tab=consultations');
        $empty->assertOk();
        $this->assertStringContainsString("\n                    0 records", $empty->getContent());
        $empty->assertSee('<td colspan="5" class="text-center text-muted py-4">No records found.</td>', false);
    }

    public function test_certificate_type_filter_and_cross_section_value_fallback(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient(1);
        $this->makeCertificate($patient->id, 'Fitness Check', 'medical');
        $this->makeCertificate($patient->id, 'Late Exam', 'excuse');

        $filtered = $this->actingAs($staff)->get('/staff/history?tab=certificates&type=excuse');
        $filtered->assertOk();
        $filtered->assertSee('<i class="bi bi-file-medical me-1"></i> Certificates History</div>', false);
        $filtered->assertSee('<option value="excuse" selected>Excuse Slip</option>', false);
        $filtered->assertSee('Late Exam');
        $filtered->assertDontSee('Fitness Check');
        $this->assertStringContainsString("\n                    1 record", $filtered->getContent());

        // "student" belongs to the patients section: the certificates select
        // cannot offer it, so both dropdown and query fall back to "all" -
        // the value can never produce invalid SQL (original line 29).
        $cross = $this->actingAs($staff)->get('/staff/history?tab=certificates&type=student');
        $cross->assertOk();
        $cross->assertSee('<option value="all" selected>All Types</option>', false);
        $cross->assertSee('Late Exam');
        $cross->assertSee('Fitness Check');
        $this->assertStringContainsString("\n                    2 records", $cross->getContent());
    }

    public function test_sick_leave_status_filter_and_empty_rejected_state(): void
    {
        $staff = $this->makeStaff();
        $employee = $this->makePatient(1, 'employee');
        DB::table('employee_details')->insert([
            'user_id' => $employee->id, 'employee_no' => 'EMP-01',
            'department' => 'HR', 'position' => 'Clerk',
        ]);
        $this->makeSickLeave($employee->id, 'Fever', 'pending');
        $this->makeSickLeave($employee->id, 'Flu', 'approved', $staff->id);

        $all = $this->actingAs($staff)->get('/staff/history?tab=sick-leaves');
        $all->assertOk();
        $all->assertSee('<i class="bi bi-thermometer me-1"></i> Sick Leaves History</div>', false);
        $all->assertSee('<span class="badge bg-warning text-dark">Pending</span>', false);
        $all->assertSee('<span class="badge bg-success">Approved</span>', false);
        $this->assertStringContainsString("\n                    2 records", $all->getContent());

        $pending = $this->actingAs($staff)->get('/staff/history?tab=sick-leaves&status=pending');
        $pending->assertOk();
        $pending->assertSee('<option value="pending" selected>Pending</option>', false);
        $pending->assertSee('<span class="badge bg-warning text-dark">Pending</span>', false);
        $pending->assertDontSee('<span class="badge bg-success">Approved</span>', false);
        $this->assertStringContainsString("\n                    1 record", $pending->getContent());

        $rejected = $this->actingAs($staff)->get('/staff/history?tab=sick-leaves&status=rejected');
        $rejected->assertOk();
        $this->assertStringContainsString("\n                    0 records", $rejected->getContent());
        $rejected->assertSee('<td colspan="7" class="text-center text-muted py-4">No records found.</td>', false);
    }

    public function test_dispense_status_filter(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient(1);
        $dispensedMed = $this->makeMedicine('Dispensia', 10, true);
        $rejectedMed = $this->makeMedicine('Rejectia', 10, true);
        $this->makeRequest($dispensedMed, 3, date('Y-m-d 08:00:00'), 'dispensed', $patient->id);
        $this->makeRequest($rejectedMed, 1, date('Y-m-d 09:00:00'), 'rejected', $patient->id);

        $all = $this->actingAs($staff)->get('/staff/history?tab=dispense');
        $all->assertOk();
        $all->assertSee('<i class="bi bi-capsule me-1"></i> Dispense History</div>', false);
        $all->assertSee('Dispensia');
        $all->assertSee('Rejectia');
        $this->assertStringContainsString("\n                    2 records", $all->getContent());

        $rejected = $this->actingAs($staff)->get('/staff/history?tab=dispense&history_status=rejected');
        $rejected->assertOk();
        $rejected->assertSee('<option value="rejected" selected>Declined</option>', false);
        $rejected->assertSee('Rejectia');
        $rejected->assertDontSee('Dispensia');
        $this->assertStringContainsString("\n                    1 record", $rejected->getContent());
    }

    /**
     * The Inventory tab is the DEDUCTION LOG - the destination of the
     * Inventory screen's View Deduction Logs action - not the stock
     * snapshot the dashboard previews. It carries the six required columns
     * in order, lists real deductions from BOTH stock systems (medicine OUT
     * movements and loan events), and its Type dropdown narrows between
     * them while its Date dropdown narrows the window.
     */
    public function test_inventory_tab_is_the_deduction_log_with_its_type_and_date_filters(): void
    {
        $staff = $this->makeStaff();
        $borrower = $this->makePatient(1);
        $aidCat = $this->makeCategory('Wound Care', 'first_aid');
        $medCat = $this->makeCategory('Analgesics', 'medicine');
        $gauze = DB::table('medicines')->insertGetId([
            'name' => 'Gauze Pad', 'category_id' => $aidCat, 'unit' => 'pcs',
            'low_stock_threshold' => 10, 'is_active' => 1,
        ]);
        $aspirin = DB::table('medicines')->insertGetId([
            'name' => 'Aspirin', 'category_id' => $medCat, 'unit' => 'tablet',
            'low_stock_threshold' => 10, 'is_active' => 1,
        ]);
        DB::table('medicine_batches')->insert([
            ['medicine_id' => $gauze, 'quantity' => 10, 'date_received' => date('Y-m-d')],
            ['medicine_id' => $aspirin, 'quantity' => 20, 'date_received' => date('Y-m-d')],
        ]);
        DB::table('stock_movements')->insert([
            ['medicine_id' => $gauze, 'type' => 'OUT', 'quantity' => 3, 'reason' => 'Wound care'],
            ['medicine_id' => $aspirin, 'type' => 'OUT', 'quantity' => 5, 'reason' => 'Fever'],
            // Receiving stock is not a deduction, so an IN row never appears.
            ['medicine_id' => $gauze, 'type' => 'IN', 'quantity' => 10, 'reason' => 'Restock batch'],
        ]);
        $crutches = $this->makeEquipment('Crutches');
        $this->makeLoan($borrower, $crutches, 2, date('Y-m-d'), date('Y-m-d H:i:s'));

        // All: both systems, the six required figures, and the log's own
        // second dropdown (the date window) beside the Type one.
        $all = $this->actingAs($staff)->get('/staff/history?tab=inventory');
        $all->assertOk();
        $all->assertSee('<i class="bi bi-box-seam me-1"></i> Inventory History</div>', false);
        $all->assertSee('>Total Stock</th>', false)
            ->assertSee('<th>Unit</th>', false)
            ->assertSee('>Quantity Deducted</th>', false)
            ->assertSee('>Total Pieces Remaining</th>', false);
        $all->assertSee('<select id="historyFilter2" name="filter"', false)
            ->assertSee('All Dates');
        $all->assertSee('Gauze Pad')
            ->assertSee('Aspirin')
            ->assertSee('Crutches')
            ->assertDontSee('Restock batch', false);
        $this->assertStringContainsString("\n                    3 records", $all->getContent());

        // The Medicine option narrows to the two OUT movements.
        $med = $this->actingAs($staff)->get('/staff/history?tab=inventory&type=medicine');
        $med->assertOk();
        $med->assertSee('<option value="medicine" selected>Medicine</option>', false);
        $med->assertSee('Gauze Pad')
            ->assertSee('Aspirin')
            ->assertDontSee('Crutches');
        $this->assertStringContainsString("\n                    2 records", $med->getContent());

        // The Equipment option narrows to the loan event.
        $eq = $this->actingAs($staff)->get('/staff/history?tab=inventory&type=equipment');
        $eq->assertOk();
        $eq->assertSee('<option value="equipment" selected>Equipment</option>', false);
        $eq->assertSee('Crutches')
            ->assertDontSee('Gauze Pad');
        $this->assertStringContainsString("\n                    1 record", $eq->getContent());

        // A junk value collapses to "all" for both the dropdown and the query.
        $junk = $this->actingAs($staff)->get('/staff/history?tab=inventory&type=first_aid');
        $junk->assertOk();
        $junk->assertSee('<option value="all" selected>All Types</option>', false);
        $this->assertStringContainsString("\n                    3 records", $junk->getContent());

        // The date window is a live second filter with its own options.
        $today = $this->actingAs($staff)->get('/staff/history?tab=inventory&filter=today');
        $today->assertOk();
        $today->assertSee('<option value="today" selected>Today</option>', false);
        $this->assertStringContainsString("\n                    3 records", $today->getContent());
    }

    public function test_equipment_loan_status_filters_including_overdue_and_junk(): void
    {
        $staff = $this->makeStaff();
        $projector = $this->makeEquipment('Projector');
        $stethoscope = $this->makeEquipment('Stethoscope Set');
        // Overdue candidate: borrowed three days ago, still out.
        $this->makeLoan($staff, $projector, 1, date('Y-m-d', strtotime('-3 days')), date('Y-m-d H:i:s', strtotime('-3 days')));
        $this->makeLoan($staff, $stethoscope, 1, date('Y-m-d', strtotime('-5 days')), date('Y-m-d H:i:s', strtotime('-5 days')), 'returned');

        $all = $this->actingAs($staff)->get('/staff/history?tab=equipment-loans');
        $all->assertOk();
        $all->assertSee('<i class="bi bi-arrow-left-right me-1"></i> Equipment Loans History</div>', false);
        $all->assertSee('<label class="form-label" for="historyFilter">Loan Status</label>', false);
        $all->assertSee('Projector');
        $all->assertSee('Stethoscope Set');
        $this->assertStringContainsString("\n                    2 records", $all->getContent());

        $returned = $this->actingAs($staff)->get('/staff/history?tab=equipment-loans&status=returned');
        $returned->assertOk();
        $returned->assertSee('<option value="returned" selected>Returned</option>', false);
        $returned->assertSee('Stethoscope Set');
        $returned->assertDontSee('Projector');
        $this->assertStringContainsString("\n                    1 record", $returned->getContent());

        // Overdue = borrowed AND borrowed_at older than one day.
        $overdue = $this->actingAs($staff)->get('/staff/history?tab=equipment-loans&status=overdue');
        $overdue->assertOk();
        $overdue->assertSee('Projector');
        $overdue->assertDontSee('Stethoscope Set');
        $this->assertStringContainsString("\n                    1 record", $overdue->getContent());

        // Feature 3: this tab colours its badge from the same expression it
        // filters with, so the red row always is a row the Overdue filter
        // just returned - and the grey spellings are gone.
        $overdue->assertSee('<span class="badge bg-danger">Overdue</span>', false);
        $overdue->assertDontSee('<span class="badge bg-secondary">Overdue</span>', false);
        $overdue->assertDontSee('<span class="badge bg-orange">Borrowed</span>', false);
        $returned->assertSee('<span class="badge bg-success">Returned</span>', false);
        $returned->assertDontSee('<span class="badge bg-secondary">Returned</span>', false);
        $all->assertSee('<span class="badge bg-danger">Overdue</span>', false);
        $all->assertSee('<span class="badge bg-success">Returned</span>', false);

        // Unknown value falls back to the unfiltered list.
        $junk = $this->actingAs($staff)->get('/staff/history?tab=equipment-loans&status=banana');
        $junk->assertOk();
        $junk->assertSee('<option value="all" selected>All Loans</option>', false);
        $this->assertStringContainsString("\n                    2 records", $junk->getContent());
    }

    // ---------------------------------------------------------------------
    // Shell, script, method parity, role parity
    // ---------------------------------------------------------------------

    public function test_sidebar_marks_history_active_and_script_carries_the_tab_base(): void
    {
        $staff = $this->makeStaff();

        $response = $this->actingAs($staff)->get('/staff/history');
        $content = $response->getContent();

        $response->assertOk();
        $response->assertSee('href="http://localhost/staff/history" class="nav-link active">', false);
        // var base is the raw JSON URL (never HTML-escaped - {!! !!}).
        $this->assertStringContainsString('var base = "http:\/\/localhost\/staff\/history";', $content);
        $this->assertStringContainsString("document.querySelectorAll('[data-history-tab]')", $content);
        $this->assertStringContainsString("window.location.href = base + '?tab=' + encodeURIComponent(", $content);
    }

    public function test_post_renders_the_same_read_only_page(): void
    {
        $staff = $this->makeStaff();
        $this->makePatient(1);

        $default = $this->actingAs($staff)->post('/staff/history');
        $default->assertOk();
        $default->assertSee('<i class="bi bi-people me-1"></i> Patients History</div>', false);

        // Query string drives the tab on POST too (the reference only ever
        // reads $_GET; its own POST body is ignored - likewise here).
        $dispense = $this->actingAs($staff)->post('/staff/history?tab=dispense');
        $dispense->assertOk();
        $dispense->assertSee('Dispense History');
    }

    public function test_role_parity(): void
    {
        $staff = $this->makeStaff();
        $student = $this->makePatient(1, 'student');
        $employee = $this->makePatient(2, 'employee');
        $admin = User::create([
            'username' => 'admin',
            'email' => 'admin@school.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->get('/staff/history')->assertRedirect(route('login'));
        $this->actingAs($admin)->get('/staff/history')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($student)->get('/staff/history')->assertRedirect(route('user.dashboard'));
        $this->actingAs($employee)->get('/staff/history')->assertRedirect(route('user.dashboard'));
        $this->actingAs($staff)->get('/staff/history')->assertOk();
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

    private function makeConsultation(string $date, int $patientId, ?string $complaint = null, ?string $diagnosis = null): int
    {
        return DB::table('consultations')->insertGetId([
            'patient_id' => $patientId,
            'complaint' => $complaint,
            'diagnosis' => $diagnosis,
            'consultation_date' => $date,
        ]);
    }

    private function makeCertificate(int $userId, string $purpose, string $type = 'medical'): int
    {
        return DB::table('medical_certificates')->insertGetId([
            'user_id' => $userId,
            'cert_type' => $type,
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

    private function makeCategory(string $name, string $type): int
    {
        return DB::table('medicine_categories')->insertGetId(['name' => $name, 'type' => $type]);
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

    private function makeRequest(int $medicineId, int $quantity, string $requestedAt, string $status, int $userId): int
    {
        return DB::table('medicine_requests')->insertGetId([
            'user_id' => $userId,
            'medicine_id' => $medicineId,
            'quantity' => $quantity,
            'status' => $status,
            'requested_at' => $requestedAt,
        ]);
    }
}

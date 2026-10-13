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
 * Patient Activity History - the combined activity feed behind the Patient
 * Overview's "View All Activities" button (GET /staff/patient_activities).
 *
 * Against the isolated in-memory SQLite connection phpunit.xml selects
 * (DB_CONNECTION=sqlite, DB_DATABASE=:memory:); every table the feed and
 * the shared layout read is created in setUp(), mirroring the live schema
 * (same shapes as PatientTest / StaffDashboardTest). The MySQL functions
 * the union uses (CONCAT, SUBDATE, NOW) are the tests/TestCase.php shims.
 *
 * Covers: the overview button + its 10-row preview limit and two-column
 * stacked layout, the merged newest-first feed across all five activity
 * families (certificates wearing the Issued badge), per-patient ownership
 * (no cross-patient leaks), sick leaves for employees only, search +
 * empty state, the activity-type filter, server-side pagination
 * preserving patient/search/type, invalid-id fallback, the View action of
 * every activity row (certificates reuse the certificates ?view= page, the
 * rest the shared read-only detail page), and that detail page's
 * staff-only + patient-ownership enforcement with no edit, delete or
 * print affordances. Also the patient's own profile picture beside their
 * name on both the overview and the history header, with the shared
 * default-avatar fallback when there is none, and id integrity across the
 * overview <-> history navigation when profiles.id has drifted away from
 * users.id (View All, Back, refresh, pagination and detail Back links must
 * all carry the patient's USER id).
 */
class PatientActivityHistoryTest extends TestCase
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

        // Shell pieces the shared layout reads (same as PatientTest).
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

        // The Patient Information / Latest Medical Record cards the profile
        // overview renders alongside the Recent Activity preview.
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

        // The five activity tables the feed unions (live-schema shapes;
        // the detail pages read every column the forms save).
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

        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
        });

        Schema::create('medicine_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('medicine_id');
            $table->integer('quantity')->default(1);
            $table->string('reason', 255)->nullable();
            $table->string('status', 20)->default('dispensed');
            $table->boolean('allergy_flag')->default(false);
            $table->text('override_reason')->nullable();
            $table->string('remarks', 255)->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->unsignedBigInteger('processed_by')->nullable();
            $table->timestamp('processed_at')->nullable();
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->integer('total_qty')->default(1);
            $table->integer('available_qty')->default(1);
            $table->boolean('is_active')->default(true);
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
    }

    // ---------------------------------------------------------------------
    // Authorization + patient validation
    // ---------------------------------------------------------------------

    public function test_guest_and_wrong_roles_cannot_reach_the_activity_page(): void
    {
        $student = $this->makeStudent();
        $admin = $this->makeUser('root', 'Root', 'Admin', ['role' => 'admin', 'account_type' => null]);

        $this->get('/staff/patient_activities?id='.$student->id)
            ->assertRedirect(route('login'));

        $this->actingAs($admin)->get('/staff/patient_activities?id='.$student->id)
            ->assertRedirect(route('admin.dashboard'));

        $this->actingAs($student)->get('/staff/patient_activities?id='.$student->id)
            ->assertRedirect(route('user.dashboard'));
    }

    public function test_missing_invalid_or_non_patient_ids_redirect_to_the_patient_list(): void
    {
        $staff = $this->makeStaff();
        $otherStaff = $this->makeUser('nurse', 'Nurse', 'Ramos', ['role' => 'staff']);

        // No id, unknown id and a staff (non-patient) id all fall back to
        // the list - ?id= can never surface a non-patient account.
        $this->actingAs($staff)->get('/staff/patient_activities')
            ->assertRedirect(route('staff.patients'));
        $this->actingAs($staff)->get('/staff/patient_activities?id=9999')
            ->assertRedirect(route('staff.patients'));
        $this->actingAs($staff)->get('/staff/patient_activities?id='.(int) $otherStaff->id)
            ->assertRedirect(route('staff.patients'));
    }

    // ---------------------------------------------------------------------
    // Overview integration: button + 10-most-recent preview
    // ---------------------------------------------------------------------

    public function test_overview_shows_recent_activity_with_the_view_all_button_and_limits_to_ten(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makeStudent();

        // 12 consultations -> the preview keeps only the 10 newest. Zero
        // padding keeps every complaint a unique substring (Complaint 1 is
        // a prefix of Complaint 12 otherwise).
        for ($i = 1; $i <= 12; $i++) {
            DB::table('consultations')->insert([
                'patient_id' => $patient->id,
                'complaint' => sprintf('Complaint %02d', $i),
                'consultation_date' => sprintf('2026-09-%02d 09:00:00', $i),
            ]);
        }

        $response = $this->actingAs($staff)->get('/staff/patients?id='.$patient->id);

        $response->assertOk();
        // The header button opens the dedicated page for THIS patient.
        $response->assertSee('Recent Activity');
        $response->assertSee(
            'href="'.url('staff/patient_activities?id='.(int) $patient->id).'" class="btn btn-sm btn-outline-primary"',
            false,
        );
        $response->assertSee('View All Activities');

        // Newest first, capped at the 10 most recent: Sep 12..Sep 3.
        $content = $response->getContent();
        $this->assertSame(10, substr_count($content, 'Complaint'), 'Overview keeps the 10 most recent activities.');
        $response->assertSee('Complaint 12');
        $response->assertSee('Complaint 03');
        $response->assertDontSee('Complaint 02');
        $response->assertDontSee('Complaint 01');
        $this->assertLessThan(
            strpos($content, 'Complaint 03'),
            strpos($content, 'Complaint 12'),
            'Newest activity renders first.',
        );
    }

    public function test_overview_shows_an_empty_state_when_the_patient_has_no_activities(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makeStudent();

        $this->actingAs($staff)->get('/staff/patients?id='.$patient->id)
            ->assertOk()
            ->assertSee('No activities recorded yet.')
            ->assertSee('View All Activities');
    }

    // ---------------------------------------------------------------------
    // Combined feed: all five families, newest first, per-patient ownership
    // ---------------------------------------------------------------------

    public function test_activity_page_combines_every_activity_type_newest_first(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makeEmployee();
        $medicine = DB::table('medicines')->insertGetId(['name' => 'Paracetamol 500mg']);
        $monitor = DB::table('equipment')->insertGetId(['name' => 'Patient Monitor']);

        DB::table('consultations')->insert([
            'patient_id' => $patient->id, 'complaint' => 'Persistent cough',
            'consultation_date' => '2026-09-01 10:30:00',
        ]);
        DB::table('medicine_requests')->insert([
            'user_id' => $patient->id, 'medicine_id' => $medicine, 'quantity' => 6,
            'status' => 'dispensed', 'requested_at' => '2026-09-02 09:15:00',
        ]);
        DB::table('equipment_loans')->insert([
            'user_id' => $patient->id, 'equipment_id' => $monitor, 'quantity' => 1,
            'borrow_date' => '2026-09-03', 'borrowed_at' => '2026-09-03 08:00:00',
            'status' => 'returned', 'return_date' => '2026-09-04',
        ]);
        DB::table('medical_certificates')->insert([
            'user_id' => $patient->id, 'cert_no' => 'MED-0001', 'purpose' => 'Fitness to Work',
            'issued_at' => '2026-09-04 11:00:00',
        ]);
        DB::table('sick_leaves')->insert([
            'user_id' => $patient->id, 'reason' => 'Migraine', 'start_date' => '2026-09-05',
            'status' => 'approved', 'created_at' => '2026-09-05 07:30:00',
        ]);

        $response = $this->actingAs($staff)->get('/staff/patient_activities?id='.$patient->id);

        $response->assertOk();
        // Identity summary + Back link to the same patient's overview.
        $response->assertSee('Patient Activity History');
        $response->assertSee('Maria Santos');
        $response->assertSee('Employee &middot; @maria', false);
        $response->assertSee('Employee No. EMP-01', false);
        $response->assertSee(
            'href="'.url('staff/patients?id='.(int) $patient->id).'" class="btn btn-sm btn-outline-secondary"',
            false,
        );
        $response->assertSee('Back to Patient Overview');

        // Every family renders with its type label, details and status.
        $response->assertSee('Consultation');
        $response->assertSee('Persistent cough');
        $response->assertSee('Medicine Dispensed');
        $response->assertSee('Paracetamol 500mg x 6');
        $response->assertSee('<span class="badge bg-success">Dispensed</span>', false);
        $response->assertSee('Equipment Loan');
        $response->assertSee('Patient Monitor x 1');
        $response->assertSee('<span class="badge bg-success">Returned</span>', false);
        $response->assertSee('Medical Certificate');
        $response->assertSee('Fitness to Work');
        // Issued certificates wear the success badge (issued_at present).
        $response->assertSee('<span class="badge bg-success">Issued</span>', false);
        $response->assertSee('Sick Leave');
        $response->assertSee('Migraine');
        $response->assertSee('<span class="badge bg-success">Approved</span>', false);
        $response->assertSee('5 activities');
        $response->assertSee('All Activities');

        // Reverse chronological order: sick leave (Sep 5) first,
        // consultation (Sep 1) last.
        $content = $response->getContent();
        $this->assertLessThan(strpos($content, 'Fitness to Work'), strpos($content, 'Migraine'));
        $this->assertLessThan(strpos($content, 'Paracetamol 500mg x 6'), strpos($content, 'Fitness to Work'));
        $this->assertLessThan(strpos($content, 'Persistent cough'), strpos($content, 'Patient Monitor x 1'));
    }

    public function test_activity_page_never_exposes_another_patients_records(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makeStudent('juan', 'Juan', 'Dela Cruz');
        $other = $this->makeStudent('maria', 'Maria', 'Santos');

        DB::table('consultations')->insert([
            'patient_id' => $patient->id, 'complaint' => 'Own cough',
            'consultation_date' => '2026-09-01 10:00:00',
        ]);
        DB::table('consultations')->insert([
            'patient_id' => $other->id, 'complaint' => 'Foreign fever',
            'consultation_date' => '2026-09-02 10:00:00',
        ]);
        DB::table('medicine_requests')->insert([
            'user_id' => $other->id, 'medicine_id' => DB::table('medicines')->insertGetId(['name' => 'Ibuprofen']),
            'quantity' => 2, 'status' => 'dispensed', 'requested_at' => '2026-09-02 11:00:00',
        ]);

        $response = $this->actingAs($staff)->get('/staff/patient_activities?id='.$patient->id);

        $response->assertOk();
        $response->assertSee('Own cough');
        $response->assertDontSee('Foreign fever');
        $response->assertDontSee('Ibuprofen');
        $response->assertSee('1 activity');
    }

    // ---------------------------------------------------------------------
    // Sick leaves: employees only
    // ---------------------------------------------------------------------

    public function test_sick_leaves_appear_for_employees_but_never_for_students(): void
    {
        $staff = $this->makeStaff();
        $employee = $this->makeEmployee();
        $student = $this->makeStudent();

        foreach ([$employee->id, $student->id] as $uid) {
            DB::table('sick_leaves')->insert([
                'user_id' => $uid, 'reason' => 'Flu', 'start_date' => '2026-09-01',
                'status' => 'pending', 'created_at' => '2026-09-01 08:00:00',
            ]);
        }

        // Employee: the sick leave shows, the badge matches the staff
        // history's Pending styling, and the filter offers its type.
        $employeeResponse = $this->actingAs($staff)->get('/staff/patient_activities?id='.$employee->id);
        $employeeResponse->assertOk();
        $employeeResponse->assertSee('Flu');
        $employeeResponse->assertSee('<span class="badge bg-warning text-dark">Pending</span>', false);
        $employeeResponse->assertSee('<option value="sick_leave">', false);

        // Student: the same record never renders and the type is not even
        // offered in the filter.
        $studentResponse = $this->actingAs($staff)->get('/staff/patient_activities?id='.$student->id);
        $studentResponse->assertOk();
        $studentResponse->assertDontSee('Flu');
        $studentResponse->assertDontSee('value="sick_leave"', false);
        $studentResponse->assertDontSee('Sick Leave</option>', false);

        // Asking for the unsupported type falls back to "all" (the patient
        // list's ?type= whitelist pattern) and still never shows the record.
        $filtered = $this->actingAs($staff)->get('/staff/patient_activities?id='.$student->id.'&type=sick_leave');
        $filtered->assertOk();
        $filtered->assertDontSee('Flu');
        $filtered->assertSee('<option value="all" selected>', false);
        $filtered->assertSee('No activities recorded yet.');
    }

    // ---------------------------------------------------------------------
    // Search, filter, pagination
    // ---------------------------------------------------------------------

    public function test_search_matches_visible_details_and_filters_the_feed(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makeStudent();
        $medicine = DB::table('medicines')->insertGetId(['name' => 'Amoxicillin 500mg']);

        DB::table('consultations')->insert([
            'patient_id' => $patient->id, 'complaint' => 'Sore throat', 'diagnosis' => 'Tonsilitis',
            'consultation_date' => '2026-09-01 09:00:00',
        ]);
        DB::table('medicine_requests')->insert([
            'user_id' => $patient->id, 'medicine_id' => $medicine, 'quantity' => 10,
            'reason' => 'Prescribed course', 'status' => 'dispensed', 'requested_at' => '2026-09-02 09:00:00',
        ]);

        // Medicine name matches the dispense row only.
        $response = $this->actingAs($staff)->get('/staff/patient_activities?id='.$patient->id.'&q=Amoxicillin');
        $response->assertOk();
        $response->assertSee('Amoxicillin 500mg x 10');
        $response->assertDontSee('Sore throat');
        $response->assertSee('1 activity');
        $response->assertSee('matching &ldquo;Amoxicillin&rdquo;', false);

        // Diagnosis matches the consultation row (search covers what each
        // row shows, plus its other record fields).
        $diagnosis = $this->actingAs($staff)->get('/staff/patient_activities?id='.$patient->id.'&q=Tonsilitis');
        $diagnosis->assertOk();
        $diagnosis->assertSee('Sore throat');
        $diagnosis->assertDontSee('Amoxicillin 500mg x 10');

        // No match -> the filtered empty state, search preserved in the box.
        $none = $this->actingAs($staff)->get('/staff/patient_activities?id='.$patient->id.'&q=Paracetamol');
        $none->assertOk();
        $none->assertSee('No activities match your search.');
        $none->assertSee('value="Paracetamol"', false);
    }

    public function test_type_filter_narrows_to_one_activity_family(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makeStudent();
        $medicine = DB::table('medicines')->insertGetId(['name' => 'Biogesic']);

        DB::table('consultations')->insert([
            'patient_id' => $patient->id, 'complaint' => 'Cough', 'consultation_date' => '2026-09-01 09:00:00',
        ]);
        DB::table('medicine_requests')->insert([
            'user_id' => $patient->id, 'medicine_id' => $medicine, 'quantity' => 1,
            'status' => 'rejected', 'requested_at' => '2026-09-02 09:00:00',
        ]);

        $response = $this->actingAs($staff)->get('/staff/patient_activities?id='.$patient->id.'&type=dispense');

        $response->assertOk();
        $response->assertSee('Biogesic x 1');
        $response->assertSee('<span class="badge bg-danger">Declined</span>', false);
        $response->assertDontSee('Cough');
        $response->assertSee('<option value="dispense" selected>', false);

        // An unknown type falls back to all (same whitelist pattern as the
        // patient list's type filter).
        $fallback = $this->actingAs($staff)->get('/staff/patient_activities?id='.$patient->id.'&type=bogus');
        $fallback->assertOk();
        $fallback->assertSee('Biogesic x 1');
        $fallback->assertSee('Cough');
        $fallback->assertSee('<option value="all" selected>', false);
    }

    public function test_pagination_pages_server_side_and_preserves_patient_search_and_type(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makeStudent();
        $medicine = DB::table('medicines')->insertGetId(['name' => 'Biogesic']);

        // 16 consultations (15 per page -> 2 pages) + a dispense row the
        // type filter will hide.
        for ($i = 1; $i <= 16; $i++) {
            DB::table('consultations')->insert([
                'patient_id' => $patient->id,
                'complaint' => sprintf('Cough %02d', $i),
                'consultation_date' => sprintf('2026-%02d-%02d 09:00:00', 1 + intdiv($i, 15), $i),
            ]);
        }
        DB::table('medicine_requests')->insert([
            'user_id' => $patient->id, 'medicine_id' => $medicine, 'quantity' => 1,
            'status' => 'dispensed', 'requested_at' => '2026-12-01 09:00:00',
        ]);

        // Page 1: 15 of the 16 matching rows, next link carries patient +
        // search + filter + page (Blade escapes each & as &amp;).
        $page1 = $this->actingAs($staff)->get('/staff/patient_activities?id='.$patient->id.'&q=Cough&type=consultation');
        $page1->assertOk();
        $page1->assertSee('16 activities matching &ldquo;Cough&rdquo;', false);
        $page1->assertSee('Cough 16');
        $page1->assertSee('Cough 02');
        $page1->assertDontSee('Cough 01');
        $page1->assertSee(
            'href="'.url('staff/patient_activities').'?'.e(http_build_query([
                'id' => (int) $patient->id, 'q' => 'Cough', 'type' => 'consultation', 'page' => 2,
            ])).'"',
            false,
        );

        // Page 2: the one remaining row, same preserved parameters.
        $page2 = $this->actingAs($staff)->get('/staff/patient_activities?id='.$patient->id.'&q=Cough&type=consultation&page=2');
        $page2->assertOk();
        $page2->assertSee('16 activities matching &ldquo;Cough&rdquo;', false);
        $page2->assertSee('Cough 01');
        $page2->assertDontSee('Cough 02');
        $page2->assertSee(
            'href="'.url('staff/patient_activities').'?'.e(http_build_query([
                'id' => (int) $patient->id, 'q' => 'Cough', 'type' => 'consultation', 'page' => 1,
            ])).'"',
            false,
        );
    }

    public function test_overview_preview_shows_borrowed_and_overdue_loan_badges(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makeStudent();
        $monitor = DB::table('equipment')->insertGetId(['name' => 'Patient Monitor']);

        // One loan overdue since yesterday (the same SUBDATE(NOW(), 1)
        // expression the staff loan history's badge uses), one fresh.
        DB::table('equipment_loans')->insert([
            'user_id' => $patient->id, 'equipment_id' => $monitor, 'quantity' => 1,
            'borrow_date' => date('Y-m-d', strtotime('-3 days')),
            'borrowed_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
            'status' => 'borrowed',
        ]);
        DB::table('equipment_loans')->insert([
            'user_id' => $patient->id, 'equipment_id' => $monitor, 'quantity' => 2,
            'borrow_date' => date('Y-m-d'), 'borrowed_at' => date('Y-m-d H:i:s'),
            'status' => 'borrowed',
        ]);

        $response = $this->actingAs($staff)->get('/staff/patient_activities?id='.$patient->id);

        $response->assertOk();
        $response->assertSee('<span class="badge bg-danger">Overdue</span>', false);
        $response->assertSee('<span class="badge bg-orange">Borrowed</span>', false);
        $response->assertSee('Patient Monitor x 1');
        $response->assertSee('Patient Monitor x 2');
    }

    // ---------------------------------------------------------------------
    // Record links: every activity opens its own read-only record
    // ---------------------------------------------------------------------

    public function test_every_activity_opens_its_record_through_the_view_action(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makeEmployee();
        $medicine = DB::table('medicines')->insertGetId(['name' => 'Biogesic']);
        $monitor = DB::table('equipment')->insertGetId(['name' => 'Patient Monitor']);

        $consultation = DB::table('consultations')->insertGetId([
            'patient_id' => $patient->id, 'complaint' => 'Cough', 'consultation_date' => '2026-09-01 09:00:00',
        ]);
        $certificate = DB::table('medical_certificates')->insertGetId([
            'user_id' => $patient->id, 'purpose' => 'Excuse Slip', 'issued_at' => '2026-09-02 09:00:00',
        ]);
        $dispense = DB::table('medicine_requests')->insertGetId([
            'user_id' => $patient->id, 'medicine_id' => $medicine, 'quantity' => 1,
            'status' => 'dispensed', 'requested_at' => '2026-09-03 09:00:00',
        ]);
        $loan = DB::table('equipment_loans')->insertGetId([
            'user_id' => $patient->id, 'equipment_id' => $monitor, 'quantity' => 1,
            'borrow_date' => '2026-09-04', 'borrowed_at' => '2026-09-04 09:00:00', 'status' => 'borrowed',
        ]);
        $leave = DB::table('sick_leaves')->insertGetId([
            'user_id' => $patient->id, 'reason' => 'Flu', 'start_date' => '2026-09-05',
            'status' => 'pending', 'created_at' => '2026-09-05 09:00:00',
        ]);

        $response = $this->actingAs($staff)->get('/staff/patient_activities?id='.$patient->id);

        $response->assertOk();
        // Certificates reuse the existing certificates page (?view= renders
        // the certificate document itself); every other family opens the
        // shared read-only activity detail page - the correct record for
        // the correct type, one View action per row, so no broken or
        // invented href can hide among them.
        $response->assertSee('href="'.e(url('staff/certificates?view='.$certificate)).'"', false);
        $response->assertSee('href="'.e(url('staff/patient_activity_detail?type=consultation&id='.$consultation)).'"', false);
        $response->assertSee('href="'.e(url('staff/patient_activity_detail?type=dispense&id='.$dispense)).'"', false);
        $response->assertSee('href="'.e(url('staff/patient_activity_detail?type=equipment_loan&id='.$loan)).'"', false);
        $response->assertSee('href="'.e(url('staff/patient_activity_detail?type=sick_leave&id='.$leave)).'"', false);
        $this->assertSame(5, substr_count($response->getContent(), 'title="View record"'), 'Every activity row carries exactly one View action.');
    }

    // ---------------------------------------------------------------------
    // Overview two-column layout + read-only record detail pages
    // ---------------------------------------------------------------------

    public function test_overview_uses_a_two_column_layout_with_a_stacked_activity_list(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makeStudent();
        $consultation = DB::table('consultations')->insertGetId([
            'patient_id' => $patient->id, 'complaint' => 'Persistent cough',
            'consultation_date' => '2026-09-01 10:30:00',
        ]);

        $response = $this->actingAs($staff)->get('/staff/patients?id='.$patient->id);

        $response->assertOk();
        // Narrow left column (profile + latest medical record) beside the
        // wider right one (recent activities); col-12 collapses both into
        // one vertical stack below the lg breakpoint.
        $response->assertSee('col-12 col-lg-4', false);
        $response->assertSee('col-12 col-lg-8', false);
        // The preview renders as a vertically stacked list - one item per
        // activity with icon, title, description, date/time, status badge
        // and the View action - not as a table.
        $response->assertSee('list-group list-group-flush', false);
        $response->assertSee('<span class="fw-semibold">Consultation</span>', false);
        $response->assertSee('Persistent cough');
        $response->assertSee('title="View record"', false);
        $response->assertSee('href="'.e(url('staff/patient_activity_detail?type=consultation&id='.$consultation)).'"', false);
        $response->assertDontSee('<thead>');
    }

    public function test_the_overview_and_history_show_the_patients_own_profile_picture(): void
    {
        $staff = $this->makeStaff();
        $withPhoto = $this->makeStudent('juan', 'Juan', 'Dela Cruz');
        $other = $this->makeEmployee('maria', 'Maria', 'Santos');

        // Stored pictures follow the upload convention {ownerId}.{ext}.
        DB::table('profiles')->where('user_id', $withPhoto->id)->update(['photo' => $withPhoto->id.'.png']);
        DB::table('profiles')->where('user_id', $other->id)->update(['photo' => $other->id.'.jpg']);

        $juanPng = asset('assets/images/profiles/'.$withPhoto->id.'.png');
        $mariaJpg = asset('assets/images/profiles/'.$other->id.'.jpg');

        // Overview: Juan's own picture beside his name, never Maria's.
        $overview = $this->actingAs($staff)->get('/staff/patients?id='.$withPhoto->id);
        $overview->assertOk();
        $overview->assertSee('src="'.$juanPng.'"', false);
        $overview->assertDontSee($mariaJpg, false);
        // A picture that later goes missing swaps to the default avatar.
        $overview->assertSee('this.nextElementSibling.style.display', false);

        // Activity history header: the SAME picture for the same patient.
        $history = $this->actingAs($staff)->get('/staff/patient_activities?id='.$withPhoto->id);
        $history->assertOk();
        $history->assertSee('src="'.$juanPng.'"', false);
        $history->assertDontSee($mariaJpg, false);

        // A second patient gets THEIR picture on both pages, never Juan's.
        $otherOverview = $this->actingAs($staff)->get('/staff/patients?id='.$other->id);
        $otherOverview->assertOk();
        $otherOverview->assertSee('src="'.$mariaJpg.'"', false);
        $otherOverview->assertDontSee($juanPng, false);

        $otherHistory = $this->actingAs($staff)->get('/staff/patient_activities?id='.$other->id);
        $otherHistory->assertOk();
        $otherHistory->assertSee('src="'.$mariaJpg.'"', false);
        $otherHistory->assertDontSee($juanPng, false);
    }

    public function test_both_pages_fall_back_to_the_default_avatar_when_there_is_no_picture(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makeStudent(); // profiles.photo stays NULL

        foreach (['/staff/patients?id='.$patient->id, '/staff/patient_activities?id='.$patient->id] as $uri) {
            $response = $this->actingAs($staff)->get($uri);

            $response->assertOk();
            // The default placeholder circle, and no <img> pointing into the
            // picture directory (a stray src would render as a broken image).
            $response->assertSee('account-avatar account-avatar-lg account-avatar-empty', false);
            $response->assertDontSee('assets/images/profiles/', false);
        }
    }

    public function test_overview_and_history_navigation_always_carries_the_patients_user_id(): void
    {
        $staff = $this->makeStaff(); // user 1 + profile 1

        // Desync profiles.id from users.id the way the live database drifts
        // (a user row with no profile): the decoy takes the next user id
        // while the profile sequence stays put, so patientA and patientB
        // each get a PROFILE id one behind their USER id - patientB's
        // profile id lands exactly on patientA's user id, the collision
        // behind the reported misnavigation (Ramil's history opened
        // Marinel's; Marinel's Back opened Louis's).
        $decoy = User::create([
            'username' => 'decoy',
            'email' => 'decoy@school.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => 'user',
            'account_type' => 'student',
            'status' => 'active',
        ]); // no Profile row, on purpose

        $patientA = $this->makeStudent('patienta', 'Anna', 'Alpha');
        $patientB = $this->makeStudent('patientb', 'Bruno', 'Bravo');

        $idA = (int) $patientA->id;
        $idB = (int) $patientB->id;
        $decoyId = (int) $decoy->id;
        $profileIdOfB = (int) DB::table('profiles')->where('user_id', $idB)->value('id');

        // Setup sanity: the ids really have drifted into the buggy shape.
        $this->assertNotSame($idB, $profileIdOfB, 'patientB: profile id must differ from user id');
        $this->assertSame($idA, $profileIdOfB, "patientB's profile id must equal patientA's user id");

        // Patient records: A gets one distinctive complaint, B gets enough
        // for a second history page.
        DB::table('consultations')->insert([
            'patient_id' => $idA, 'complaint' => 'Anna-only checkup',
            'consultation_date' => '2026-09-01 08:00:00',
        ]);
        for ($i = 1; $i <= 16; $i++) {
            DB::table('consultations')->insert([
                'patient_id' => $idB, 'complaint' => 'Bravo cough '.$i,
                'consultation_date' => sprintf('2026-09-%02d 09:00:00', $i),
            ]);
        }

        // ---- Overview (patient B): View All must carry B's USER id. ----
        $overviewB = $this->actingAs($staff)->get('/staff/patients?id='.$idB);
        $overviewB->assertOk();
        $overviewB->assertSee('staff/patient_activities?id='.$idB, false);
        $overviewB->assertDontSee('staff/patient_activities?id='.$idA, false);
        $overviewB->assertDontSee('staff/patient_activities?id='.$decoyId, false);
        // The Recent Activity preview must also be B's, not A's.
        $overviewB->assertSee('Bravo cough 16');
        $overviewB->assertDontSee('Anna-only checkup');

        // ---- Overview (patient A): same rule for the other patient. ----
        $overviewA = $this->actingAs($staff)->get('/staff/patients?id='.$idA);
        $overviewA->assertOk();
        $overviewA->assertSee('staff/patient_activities?id='.$idA, false);
        $overviewA->assertDontSee('staff/patient_activities?id='.$decoyId, false);
        $overviewA->assertSee('Anna-only checkup');
        $overviewA->assertDontSee('Bravo cough 16');

        // ---- History (patient B): Back, refresh and pagination must all
        // stay on B - never A - and show only B's records. ----
        $historyB = $this->actingAs($staff)->get('/staff/patient_activities?id='.$idB);
        $historyB->assertOk();
        $historyB->assertSee('Bruno Bravo');
        $historyB->assertDontSee('Anna Alpha');
        $historyB->assertSee('staff/patients?id='.$idB, false);   // Back link
        $historyB->assertDontSee('staff/patients?id='.$idA, false);
        $historyB->assertSee('<input type="hidden" name="id" value="'.$idB.'">', false);
        $historyB->assertSee('Bravo cough 16');
        $historyB->assertDontSee('Anna-only checkup');            // ownership
        $historyB->assertSee('staff/patient_activities?id='.$idB.'&amp;page=2', false);

        // Refresh: the URL alone re-resolves the same patient.
        $refreshed = $this->actingAs($staff)->get('/staff/patient_activities?id='.$idB);
        $refreshed->assertOk();
        $refreshed->assertSee('staff/patients?id='.$idB, false);
        $refreshed->assertDontSee('staff/patients?id='.$idA, false);

        // ---- History (patient A): Back must return to A. ----
        $historyA = $this->actingAs($staff)->get('/staff/patient_activities?id='.$idA);
        $historyA->assertOk();
        $historyA->assertSee('Anna Alpha');
        $historyA->assertSee('staff/patients?id='.$idA, false);
        $historyA->assertDontSee('staff/patients?id='.$decoyId, false);
        $historyA->assertSee('Anna-only checkup');
        $historyA->assertDontSee('Bravo cough 16');

        // ---- Read-only detail page: Back to history keeps B's id. ----
        $bConsultation = (int) DB::table('consultations')
            ->where('patient_id', $idB)->orderByDesc('id')->value('id');
        $detailB = $this->actingAs($staff)
            ->get('/staff/patient_activity_detail?type=consultation&id='.$bConsultation);
        $detailB->assertOk();
        $detailB->assertSee('staff/patient_activities?id='.$idB, false);
        $detailB->assertDontSee('staff/patient_activities?id='.$idA, false);
    }

    public function test_activity_detail_pages_render_every_record_read_only(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makeEmployee('maria', 'Maria', 'Santos', ['department' => 'Registrar']);
        $nurse = $this->makeUser('nurse', 'Clinic', 'Nurse', ['role' => 'staff', 'account_type' => 'employee']);
        $medicine = DB::table('medicines')->insertGetId(['name' => 'Biogesic']);
        $monitor = DB::table('equipment')->insertGetId(['name' => 'Patient Monitor']);

        $consultation = DB::table('consultations')->insertGetId([
            'patient_id' => $patient->id, 'staff_id' => $nurse->id,
            'complaint' => 'Persistent cough', 'diagnosis' => 'Common cold',
            'treatment' => 'Rest and fluids', 'notes' => 'Advised to hydrate',
            'blood_pressure' => '120/80', 'temperature' => 37.2, 'pulse' => 80,
            'respiration' => 18, 'weight_kg' => 55.5, 'height_cm' => 165.0,
            'consultation_date' => '2026-09-01 10:30:00',
        ]);
        $dispense = DB::table('medicine_requests')->insertGetId([
            'user_id' => $patient->id, 'medicine_id' => $medicine, 'quantity' => 6,
            'reason' => 'Headache', 'status' => 'dispensed', 'allergy_flag' => 1,
            'override_reason' => 'Checked - no reaction', 'remarks' => 'Take after meals',
            'requested_at' => '2026-09-02 09:15:00',
            'processed_by' => $nurse->id, 'processed_at' => '2026-09-02 10:00:00',
        ]);
        $loan = DB::table('equipment_loans')->insertGetId([
            'user_id' => $patient->id, 'equipment_id' => $monitor, 'quantity' => 1,
            'borrow_date' => '2026-09-03', 'borrowed_at' => '2026-09-03 08:00:00',
            'due_date' => '2026-09-10', 'status' => 'borrowed',
            'issued_by' => $nurse->id, 'notes' => 'For home monitoring',
        ]);
        $leave = DB::table('sick_leaves')->insertGetId([
            'user_id' => $patient->id, 'reason' => 'Migraine', 'start_date' => '2026-09-05',
            'end_date' => '2026-09-06', 'status' => 'approved',
            'reviewed_by' => $nurse->id, 'review_note' => 'Approved by clinic',
            'created_at' => '2026-09-05 07:30:00', 'reviewed_at' => '2026-09-05 09:00:00',
        ]);

        // Consultation - every recorded field, including the vitals.
        $response = $this->actingAs($staff)->get('/staff/patient_activity_detail?type=consultation&id='.$consultation);
        $response->assertOk()
            ->assertSee('Consultation Details')
            ->assertSee('Back to Patient Activity History')
            ->assertSee('Maria Santos')
            ->assertSee('Employee &middot; @maria', false)
            ->assertSee('Persistent cough')
            ->assertSee('Common cold')
            ->assertSee('Rest and fluids')
            ->assertSee('Advised to hydrate')
            ->assertSee('120/80')
            ->assertSee('55.5')
            ->assertSee('Clinic Nurse');
        // Read-only: no form, edit, delete or print affordances exist here.
        $response->assertDontSee('<form')
            ->assertDontSee('action=delete')
            ->assertDontSee('bi-printer')
            ->assertDontSee('bi-pencil');

        // Medicine dispensing - medicine, quantity, reason, status badge,
        // allergy override + reason, remarks and the processing staff.
        $this->actingAs($staff)->get('/staff/patient_activity_detail?type=dispense&id='.$dispense)
            ->assertOk()
            ->assertSee('Medicine Dispensed Details')
            ->assertSee('Biogesic')
            ->assertSee('Headache')
            ->assertSee('<span class="badge bg-success">Dispensed</span>', false)
            ->assertSee('Checked - no reaction')
            ->assertSee('Take after meals')
            ->assertSee('Clinic Nurse');

        // Equipment loan - borrow/due bookkeeping, notes and staff.
        $this->actingAs($staff)->get('/staff/patient_activity_detail?type=equipment_loan&id='.$loan)
            ->assertOk()
            ->assertSee('Equipment Loan Details')
            ->assertSee('Patient Monitor')
            ->assertSee('Sep 03, 2026')
            ->assertSee('Sep 10, 2026')
            ->assertSee('For home monitoring')
            ->assertSee('Clinic Nurse');

        // Sick leave - period, status badge, review line + note.
        $this->actingAs($staff)->get('/staff/patient_activity_detail?type=sick_leave&id='.$leave)
            ->assertOk()
            ->assertSee('Sick Leave Details')
            ->assertSee('Migraine')
            ->assertSee('Sep 05, 2026')
            ->assertSee('Sep 06, 2026')
            ->assertSee('<span class="badge bg-success">Approved</span>', false)
            ->assertSee('Reviewed by Clinic Nurse')
            ->assertSee('Approved by clinic');
    }

    public function test_activity_detail_enforces_staff_auth_and_patient_ownership(): void
    {
        $staff = $this->makeStaff();
        $student = $this->makeStudent();
        $admin = $this->makeUser('root', 'Root', 'Admin', ['role' => 'admin', 'account_type' => null]);

        $consultation = DB::table('consultations')->insertGetId([
            'patient_id' => $student->id, 'complaint' => 'Cough', 'consultation_date' => '2026-09-01 09:00:00',
        ]);
        // A record owned by a STAFF account is not a patient record.
        $staffOwned = DB::table('consultations')->insertGetId([
            'patient_id' => $staff->id, 'complaint' => 'Staff row', 'consultation_date' => '2026-09-01 09:00:00',
        ]);
        // Sick leave is an employee-only family: a student row is never a
        // valid target.
        $studentLeave = DB::table('sick_leaves')->insertGetId([
            'user_id' => $student->id, 'reason' => 'Flu', 'start_date' => '2026-09-05',
        ]);

        $this->get('/staff/patient_activity_detail?type=consultation&id='.$consultation)
            ->assertRedirect(route('login'));
        $this->actingAs($student)->get('/staff/patient_activity_detail?type=consultation&id='.$consultation)
            ->assertRedirect(route('user.dashboard'));
        $this->actingAs($admin)->get('/staff/patient_activity_detail?type=consultation&id='.$consultation)
            ->assertRedirect(route('admin.dashboard'));

        $response = $this->actingAs($staff);
        // Unknown type (incl. certificate, which keeps its own ?view= page),
        // missing/unknown id, a non-patient owner and a student sick leave
        // all fall back to the patient list.
        $response->get('/staff/patient_activity_detail?type=certificate&id='.$consultation)->assertRedirect(route('staff.patients'));
        $response->get('/staff/patient_activity_detail?id='.$consultation)->assertRedirect(route('staff.patients'));
        $response->get('/staff/patient_activity_detail?type=consultation&id=9999')->assertRedirect(route('staff.patients'));
        $response->get('/staff/patient_activity_detail?type=consultation&id='.(int) $staffOwned)->assertRedirect(route('staff.patients'));
        $response->get('/staff/patient_activity_detail?type=sick_leave&id='.$studentLeave)->assertRedirect(route('staff.patients'));

        // The patient-owned record still opens for staff.
        $response->get('/staff/patient_activity_detail?type=consultation&id='.$consultation)->assertOk();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function makeStaff(): User
    {
        return $this->makeUser('staff', 'Clinic', 'Staff', [
            'role' => 'staff',
            'account_type' => 'employee',
        ]);
    }

    /** Seed a role=user patient with profile + student/employee detail row. */
    private function makePatient(
        string $username,
        string $firstName,
        string $lastName,
        string $type,
        array $overrides = [],
    ): User {
        $user = $this->makeUser($username, $firstName, $lastName, [
            'role' => 'user',
            'account_type' => $type,
        ]);

        if ($type === 'student') {
            DB::table('student_details')->insert([
                'user_id' => $user->id,
                'student_no' => $overrides['student_no'] ?? '2024-0001',
                'course' => $overrides['course'] ?? 'BSIT',
                'year_level' => $overrides['year_level'] ?? '3',
                'section' => $overrides['section'] ?? 'A',
            ]);
        } else {
            DB::table('employee_details')->insert([
                'user_id' => $user->id,
                'employee_no' => $overrides['employee_no'] ?? 'EMP-01',
                'department' => $overrides['department'] ?? null,
                'position' => $overrides['position'] ?? null,
            ]);
        }

        return $user->fresh();
    }

    private function makeStudent(
        string $username = 'juan',
        string $firstName = 'Juan',
        string $lastName = 'Dela Cruz',
        array $overrides = [],
    ): User {
        return $this->makePatient($username, $firstName, $lastName, 'student', $overrides);
    }

    private function makeEmployee(
        string $username = 'maria',
        string $firstName = 'Maria',
        string $lastName = 'Santos',
        array $overrides = [],
    ): User {
        return $this->makePatient($username, $firstName, $lastName, 'employee', $overrides);
    }

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

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
 * Phase 6 patients tests against the isolated in-memory SQLite connection
 * that phpunit.xml selects (DB_CONNECTION=sqlite, DB_DATABASE=:memory:).
 *
 * Isolated because the CREATE path writes users/profiles/student_details -
 * writing test rows into the live school_clinic database is forbidden. Every
 * table the two patients pages read or write is created in setUp() here;
 * no MySQL connection is ever opened by these tests.
 *
 * SQL parity note: the port uses MySQL's CONCAT(), which SQLite < 3.44 does
 * not ship - setUp() registers the same NULL-propagating semantics as a
 * SQLite function so the exact production SQL can run unmodified.
 */
class PatientTest extends TestCase
{
    /** Password used by every seeded account. */
    private const PASSWORD = 'secret123';

    protected function setUp(): void
    {
        parent::setUp();

        // MySQL CONCAT(): NULL if ANY argument is NULL, else concatenation.
        DB::getPdo()->sqliteCreateFunction('concat', function (...$args): ?string {
            foreach ($args as $arg) {
                if ($arg === null) {
                    return null;
                }
            }

            return implode('', $args);
        });

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
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('student_no', 40);
            $table->string('course', 120)->nullable();
            $table->string('year_level', 40)->nullable();
            $table->string('section', 60)->nullable();
        });

        Schema::create('employee_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('employee_no', 40);
            $table->string('department', 120)->nullable();
            $table->string('position', 120)->nullable();
        });

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

        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->text('complaint')->nullable();
            $table->text('diagnosis')->nullable();
            $table->timestamp('consultation_date')->useCurrent();
        });

        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
        });

        Schema::create('dispense_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('medicine_id');
            $table->integer('quantity');
            $table->timestamp('dispensed_at')->useCurrent();
        });

        // The tables the Patient Activity History feed reads (shapes mirror
        // the live schema, same as StaffDashboardTest): medicine_requests,
        // equipment + equipment_loans, medical_certificates and sick_leaves.
        Schema::create('medicine_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('medicine_id');
            $table->integer('quantity')->default(1);
            $table->string('reason', 255)->nullable();
            $table->string('status', 20)->default('dispensed');
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
        });

        Schema::create('medical_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('cert_no', 40)->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('cert_type', 30)->default('medical');
            $table->string('purpose', 255)->nullable();
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
            $table->timestamp('created_at')->useCurrent();
        });

        // Shell pieces the shared layout reads (same as LoginTest).
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
            $table->string('action', 50);
            $table->string('table_name', 50)->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->text('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('user_id');
            $table->index('action');
        });
    }

    // ---------------------------------------------------------------------
    // Authorization (require_login(['staff']) - the two redirects)
    // ---------------------------------------------------------------------

    public function test_guest_is_redirected_to_the_login_page(): void
    {
        $this->get('/staff/patients')->assertRedirect(route('login'));
        $this->get('/staff/patient_register')->assertRedirect(route('login'));
    }

    public function test_wrong_role_is_redirected_to_the_own_dashboard(): void
    {
        $admin = $this->makeUser('admin', 'System', 'Admin', ['role' => 'admin']);
        $this->actingAs($admin)->get('/staff/patients')
            ->assertRedirect(route('admin.dashboard'));

        $student = $this->makeUser('student', 'Juan', 'Dela Cruz', [
            'role' => 'user',
            'account_type' => 'student',
        ]);
        $this->actingAs($student)->get('/staff/patients')
            ->assertRedirect(route('user.dashboard'));
    }

    // ---------------------------------------------------------------------
    // List, search, filter
    // ---------------------------------------------------------------------

    public function test_staff_sees_the_patient_list_toolbar_and_rows(): void
    {
        $staff = $this->makeStaff();
        $this->makePatient('juan', 'Juan', 'Dela Cruz', 'student', ['student_no' => '2024-0001']);
        $this->makePatient('maria', 'Maria', 'Santos', 'employee', ['employee_no' => 'EMP-01']);

        $response = $this->actingAs($staff)->get('/staff/patients');

        $response->assertOk();
        $response->assertSee('Patients');
        $response->assertSee('Juan Dela Cruz');
        $response->assertSee('Maria Santos');
        $response->assertSee('2024-0001');
        $response->assertSee('EMP-01');
        // The separate registration page is unlinked: no New Patient button
        // in the toolbar and no Register Patient entry in the sidebar.
        $response->assertDontSee('New Patient');
        $response->assertDontSee('Register Patient');
        $response->assertSee('patientSearchSuggestions');  // datalist present
        // Datalist suggestion label format: "Name - ID - @username"
        $response->assertSee('Juan Dela Cruz - 2024-0001 - @juan');
        $response->assertSee('id="patientTable"', false);
    }

    public function test_search_matches_name_username_and_id_and_type_filter_narrows(): void
    {
        $staff = $this->makeStaff();
        $this->makePatient('juan', 'Juan', 'Dela Cruz', 'student', ['student_no' => '2024-0001']);
        $this->makePatient('maria', 'Maria', 'Santos', 'employee', ['employee_no' => 'EMP-01']);

        // Search by last name. The datalist always lists every active patient
        // (original behaviour), so rows are asserted by their <td> cells.
        $this->actingAs($staff)->get('/staff/patients?q=Santos')
            ->assertOk()
            ->assertSee('<td>Maria Santos</td>', false)
            ->assertDontSee('<td>Juan Dela Cruz</td>', false);

        // Search by student number
        $this->actingAs($staff)->get('/staff/patients?q=2024-0001')
            ->assertOk()
            ->assertSee('<td>Juan Dela Cruz</td>', false)
            ->assertDontSee('<td>Maria Santos</td>', false);

        // Type filter
        $this->actingAs($staff)->get('/staff/patients?type=employee')
            ->assertOk()
            ->assertSee('<td>Maria Santos</td>', false)
            ->assertDontSee('<td>Juan Dela Cruz</td>', false);

        // Original allow-list: unknown type values fall back to 'all'
        $this->actingAs($staff)->get('/staff/patients?type=bogus')
            ->assertOk()
            ->assertSee('<td>Juan Dela Cruz</td>', false)
            ->assertSee('<td>Maria Santos</td>', false);
    }

    // ---------------------------------------------------------------------
    // Profile view (?id=)
    // ---------------------------------------------------------------------

    public function test_profile_view_renders_information_medical_allergies_and_activity(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('juan', 'Juan', 'Dela Cruz', 'student', [
            'student_no' => '2024-0001',
            'course' => 'BSIT',
            'year_level' => '3',
            'section' => 'A',
            'birthdate' => '2005-06-15',
            'contact_no' => '09171234567',
            'address' => 'Quezon City',
            'emergency_name' => 'Ana Dela Cruz',
            'emergency_contact' => '09181234567',
            'gender' => 'male',
        ]);

        DB::table('medical_records')->insert([
            'user_id' => $patient->id,
            'blood_type' => 'O+',
            'height_cm' => 170.5,
            'weight_kg' => 60.2,
            'allergies_text' => 'Peanuts',
            'existing_conditions' => 'Asthma',
            'current_medications' => 'Ventolin',
            'immunizations' => 'MMR',
            'school_year' => '2026-2027',
            'semester' => '1st',
            'submitted_at' => '2026-08-01 09:00:00',
        ]);
        DB::table('user_allergies')->insert([
            'user_id' => $patient->id,
            'allergen_name' => 'Amoxicillin',
            'severity' => 'severe',
            'notes' => 'Hives',
        ]);
        DB::table('consultations')->insert([
            'patient_id' => $patient->id,
            'staff_id' => $staff->id,
            'complaint' => 'Persistent cough for three days now',
            'diagnosis' => 'Common cold',
            'consultation_date' => '2026-09-20 10:30:00',
        ]);
        DB::table('medicines')->insert(['name' => 'Paracetamol 500mg']);
        // Dispensing writes medicine_requests rows (the table the dispense
        // page, the staff dispense history and the new activity feed read).
        DB::table('medicine_requests')->insert([
            'user_id' => $patient->id,
            'medicine_id' => DB::table('medicines')->value('id'),
            'quantity' => 6,
            'status' => 'dispensed',
            'requested_at' => '2026-09-20 10:35:00',
        ]);

        $response = $this->actingAs($staff)->get('/staff/patients?id='.$patient->id);

        $response->assertOk();
        $response->assertSee('Back to patients');
        // The Overview / Visits and dispensing pills were removed together
        // with the activity sections they switched between.
        $response->assertDontSee('Overview');
        $response->assertDontSee('Visits and dispensing');

        // Patient information card
        $response->assertSee('Patient Information');
        $response->assertSee('Juan Dela Cruz');
        $response->assertSee('Student &middot; @juan', false);
        $response->assertSee('2024-0001');
        $response->assertSee('BSIT');
        $response->assertSee('3 A'); // year + section, trimmed join
        $response->assertSee('Jun 15, 2005'); // fmt_dt port
        $response->assertSee('09171234567');
        $response->assertSee('Ana Dela Cruz');
        $response->assertSee('(09181234567)');

        // Latest medical record card. The redesign folds the linked
        // allergies list into this card and drops the free-text
        // allergies_text column ("Peanuts") from the rendered output.
        $response->assertSee('Latest Medical Record');
        $response->assertSee('O+');
        $response->assertSee('2026-2027 1st');
        $response->assertSee('Asthma');

        // Allergies: same data, now listed inside the medical record card
        // from the linked user_allergies rows (there is no separate card).
        $response->assertSee('Allergies:');
        $response->assertSee('Amoxicillin');
        $response->assertSee('Severe');
        $response->assertSee('Hives');

        // Recent Activity: ONE combined card previews the 10 newest
        // activities (consultations, dispensing, equipment loans,
        // certificates, employee sick leaves); the old per-type Recent
        // Consultations / Recent Dispensed Medicines cards stay gone. The
        // header button opens the dedicated full-history page. ('Clinic
        // Staff' is not asserted absent: the topbar account dropdown always
        // renders the logged-in staff's own name.)
        $response->assertSee('Recent Activity');
        $response->assertSee('View All Activities');
        $response->assertSee('staff/patient_activities?id='.$patient->id, false);
        $response->assertSee('Persistent cough for three days now'); // consultation complaint, untruncated
        $response->assertSee('Paracetamol 500mg x 6');
        $response->assertSee('<span class="badge bg-success">Dispensed</span>', false);
        $response->assertDontSee('Recent Consultations');
        $response->assertDontSee('Recent Dispensed Medicines');
        $response->assertDontSee('Common cold'); // the diagnosis is not inlined next to the complaint
    }

    public function test_employee_profile_shows_employee_details_instead_of_student(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('maria', 'Maria', 'Santos', 'employee', [
            'employee_no' => 'EMP-01',
            'department' => 'Registrar',
            'position' => 'Clerk',
        ]);

        $response = $this->actingAs($staff)->get('/staff/patients?id='.$patient->id);

        $response->assertOk();
        $response->assertSee('Employee No.');
        $response->assertSee('EMP-01');
        $response->assertSee('Registrar');
        $response->assertSee('Clerk');
        $response->assertDontSee('Student No.');
        $response->assertSee('No medical record submitted yet.');
        // "No linked allergies recorded." now sits inside the medical
        // record card, so it cannot show for a patient with no record.
        $response->assertDontSee('No linked allergies recorded.');
        $response->assertDontSee('No consultations.');
        $response->assertDontSee('No dispensing records.');
    }

    public function test_unknown_profile_id_falls_back_to_the_list(): void
    {
        $staff = $this->makeStaff();

        $response = $this->actingAs($staff)->get('/staff/patients?id=9999');

        $response->assertOk();
        $response->assertDontSee('Back to patients');
        $response->assertSee('Search');
        $response->assertSee('No patients found.');
    }

    // ---------------------------------------------------------------------
    // Register: GET form
    // ---------------------------------------------------------------------

    public function test_register_form_renders_with_csrf_and_account_cards(): void
    {
        $staff = $this->makeStaff(); // account_type=employee (live staff row too)

        $response = $this->actingAs($staff)->get('/staff/patient_register');

        // Served-behaviour parity: topbar.php:19 clobbers the page's
        // $accountType with the logged-in user's own account_type, so the
        // card follows the STAFF account (employee), not the default.
        $response->assertOk();
        $response->assertSee('Register Employee');
        $response->assertSee('_token', false); // @csrf
        $response->assertSee('Login Credentials');
        $response->assertSee('Student Details');
        $response->assertSee('Employee Details');
        $response->assertSee('toggleAccountType'); // inline script present

        // ?type= is dead in the original (clobbered before render) - a student
        // staff account would be the only way to see the student card.
        $this->actingAs($staff)->get('/staff/patient_register?type=student')
            ->assertOk()
            ->assertSee('Register Employee');

        $studentStaff = $this->makeUser('staff2', 'Clinic', 'Staff Two', [
            'role' => 'staff',
            'account_type' => 'student',
        ]);
        $this->actingAs($studentStaff)->get('/staff/patient_register')
            ->assertOk()
            ->assertSee('Register Student');
    }

    // ---------------------------------------------------------------------
    // Register: create (student + employee), audit, flash, redirect
    // ---------------------------------------------------------------------

    public function test_register_student_creates_three_rows_hashes_password_and_audits(): void
    {
        $staff = $this->makeStaff();

        $response = $this->actingAs($staff)->post('/staff/patient_register', $this->studentPayload());

        $response->assertRedirect(route('staff.patients'));
        $response->assertSessionHas('success', 'Patient account juan01 created successfully.');

        $user = DB::table('users')->where('username', 'juan01')->first();
        $this->assertNotNull($user);
        $this->assertSame('user', $user->role);
        $this->assertSame('student', $user->account_type);
        $this->assertSame('active', $user->status);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password_hash));
        $this->assertStringStartsWith('$2y$', $user->password_hash);

        $profile = DB::table('profiles')->where('user_id', $user->id)->first();
        $this->assertSame('Juan', $profile->first_name);
        $this->assertSame('M', $profile->middle_name);
        $this->assertSame('Dela Cruz', $profile->last_name);
        $this->assertSame('male', $profile->gender);
        $this->assertSame('2005-06-15', $profile->birthdate);
        $this->assertSame(
            'Lot 1 Street A Barangay B 1234 Manila City Metro Manila',
            $profile->address
        );
        $this->assertSame('09171234567', $profile->contact_no);
        $this->assertSame('Ana Dela Cruz', $profile->emergency_name);

        $this->assertSame(1, DB::table('student_details')->where('user_id', $user->id)->count());
        $this->assertSame('2024-0001', DB::table('student_details')->where('user_id', $user->id)->value('student_no'));
        $this->assertSame(0, DB::table('employee_details')->count());

        // Audit: original action/table/details strings, no password anywhere.
        $audit = DB::table('audit_logs')->where('action', 'create_patient')->first();
        $this->assertNotNull($audit);
        $this->assertSame('users', $audit->table_name);
        $this->assertSame($user->id, $audit->record_id);
        $this->assertSame('Registered patient juan01 (student)', $audit->details);
        $this->assertStringNotContainsString(self::PASSWORD, $audit->details);
    }

    public function test_register_employee_creates_employee_details_and_applies_the_gender_allowlist(): void
    {
        $staff = $this->makeStaff();

        $payload = $this->studentPayload();
        $payload = array_merge($payload, [
            'username' => 'maria01',
            'email' => 'maria01@school.test',
            'account_type' => 'employee',
            'employee_no' => 'EMP-09',
            'department' => 'Registrar',
            'position' => 'Clerk',
            'gender' => 'not-a-gender', // outside male|female|other -> NULL, like the original
            'student_no' => '',         // ignored for employees
        ]);

        $response = $this->actingAs($staff)->post('/staff/patient_register', $payload);

        $response->assertRedirect(route('staff.patients'));

        $user = DB::table('users')->where('username', 'maria01')->first();
        $this->assertSame('employee', $user->account_type);
        $this->assertSame(0, DB::table('student_details')->count());
        $this->assertSame(1, DB::table('employee_details')->where('user_id', $user->id)->count());
        $this->assertSame('EMP-09', DB::table('employee_details')->where('user_id', $user->id)->value('employee_no'));
        $this->assertNull(DB::table('profiles')->where('user_id', $user->id)->value('gender'));

        $this->assertSame(
            'Registered patient maria01 (employee)',
            DB::table('audit_logs')->where('action', 'create_patient')->value('details')
        );
    }

    // ---------------------------------------------------------------------
    // Register: failures (exact strings, original order, no writes)
    // ---------------------------------------------------------------------

    public function test_empty_submit_lists_the_original_messages_in_order_and_writes_nothing(): void
    {
        $staff = $this->makeStaff();

        $response = $this->actingAs($staff)->post('/staff/patient_register', [
            'username' => '',
            'email' => '',
            'password' => '',
            'first_name' => '',
            'last_name' => '',
            'student_no' => '',
            'account_type' => 'student',
        ]);

        $response->assertOk(); // same-request re-render, no redirect (original behaviour)
        $response->assertSeeInOrder([
            'Username is required.',
            'A valid email is required.',
            'First name is required.',
            'Last name is required.',
            'Password must be at least 6 characters.',
            'Student number is required.',
        ]);
        // The card does NOT follow the POSTed account_type: like the original,
        // topbar's clobber resets it to the logged-in staff account (employee)
        // while validation above still used the POSTed student type.
        $response->assertSee('Register Employee');

        // Only the staff account exists - the failed POST wrote nothing.
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(1, DB::table('profiles')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_employee_submit_requires_the_employee_number_instead(): void
    {
        $staff = $this->makeStaff();

        $response = $this->actingAs($staff)->post('/staff/patient_register', [
            'username' => 'jdoe',
            'email' => 'jdoe@school.test',
            'password' => 'secret123',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'student_no' => '2024-9999', // present, but irrelevant for employees
            'account_type' => 'employee',
        ]);

        $response->assertOk();
        $response->assertSee('Employee number is required.');
        $response->assertDontSee('Student number is required.');
        // Card = logged-in staff account (employee) via the topbar clobber -
        // it happens to match the POSTed type here, so this alone does not
        // prove the POSTed value drives the card (the student test above does).
        $response->assertSee('Register Employee');
        $this->assertSame(1, DB::table('users')->count()); // only the staff row
    }

    public function test_bad_email_and_short_password_get_their_exact_messages(): void
    {
        $staff = $this->makeStaff();

        $response = $this->actingAs($staff)->post('/staff/patient_register', array_merge(
            $this->studentPayload(),
            ['email' => 'not-an-email', 'password' => '123']
        ));

        $response->assertOk();
        $response->assertSeeInOrder([
            'A valid email is required.',
            'Password must be at least 6 characters.',
        ]);
        $response->assertDontSee('Username is required.');
        $this->assertSame(1, DB::table('users')->count()); // only the staff row
    }

    public function test_duplicate_username_or_email_is_rejected_without_writing(): void
    {
        $staff = $this->makeStaff();
        $this->makeStudent('taken', 'Existing', 'Account', ['student_no' => '2020-0001']);

        // Same username as the seeded account, different email
        $this->actingAs($staff)->post('/staff/patient_register', array_merge(
            $this->studentPayload(),
            ['username' => 'taken', 'email' => 'fresh@school.test']
        ))->assertOk()->assertSee('Username or email already exists.');

        // Same email as the seeded account, different username
        $this->actingAs($staff)->post('/staff/patient_register', array_merge(
            $this->studentPayload(),
            ['username' => 'someone-else', 'email' => 'taken@school.test']
        ))->assertOk()->assertSee('Username or email already exists.');

        $this->assertSame(2, DB::table('users')->count()); // staff + seeded 'taken'
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** A valid student registration payload (all original required fields). */
    private function studentPayload(): array
    {
        return [
            'username' => 'juan01',
            'email' => 'juan01@school.test',
            'account_type' => 'student',
            'password' => self::PASSWORD,
            'password_confirm' => self::PASSWORD,
            'first_name' => 'Juan',
            'middle_name' => 'M',
            'last_name' => 'Dela Cruz',
            'gender' => 'male',
            'birthdate' => '2005-06-15',
            'contact_no' => '09171234567',
            'lot_block' => 'Lot 1',
            'street' => 'Street A',
            'barangay' => 'Barangay B',
            'postal_code' => '1234',
            'city_municipality' => 'Manila City',
            'province' => 'Metro Manila',
            'emergency_name' => 'Ana Dela Cruz',
            'emergency_contact' => '09181234567',
            'student_no' => '2024-0001',
            'course' => 'BSIT',
            'year_level' => '3',
            'section' => 'A',
        ];
    }

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
        $user = User::create([
            'username' => $username,
            'email' => $username.'@school.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => 'user',
            'account_type' => $type,
            'status' => 'active',
        ]);

        Profile::create([
            'user_id' => $user->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birthdate' => $overrides['birthdate'] ?? null,
            'contact_no' => $overrides['contact_no'] ?? null,
            'address' => $overrides['address'] ?? null,
            'gender' => $overrides['gender'] ?? null,
            'emergency_name' => $overrides['emergency_name'] ?? null,
            'emergency_contact' => $overrides['emergency_contact'] ?? null,
        ]);

        if ($type === 'student') {
            DB::table('student_details')->insert([
                'user_id' => $user->id,
                'student_no' => $overrides['student_no'] ?? '2024-0000',
                'course' => $overrides['course'] ?? null,
                'year_level' => $overrides['year_level'] ?? null,
                'section' => $overrides['section'] ?? null,
            ]);
        } else {
            DB::table('employee_details')->insert([
                'user_id' => $user->id,
                'employee_no' => $overrides['employee_no'] ?? 'EMP-00',
                'department' => $overrides['department'] ?? null,
                'position' => $overrides['position'] ?? null,
            ]);
        }

        return $user->fresh();
    }

    private function makeStudent(string $username, string $firstName, string $lastName, array $overrides = []): User
    {
        return $this->makePatient($username, $firstName, $lastName, 'student', $overrides);
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

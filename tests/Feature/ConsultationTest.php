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
 * Phase 11 consultation tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects.
 *
 * Covers staff/consultations.php: the save handler with its two exact
 * messages and numeric quirks (lines 12-43), the ?edit= prefill (45-51),
 * the ?action=delete GET handler (53-58), the dropdown query (61-65) and
 * the search/filter history (71-100, incl. the invalid-filter split where
 * the query collapses to 'all' but the <select> reads the raw value), plus
 * user/my_consultations.php (own history, conditional chips).
 *
 * SQL parity: SUBDATE(CURDATE(), n) is the port's plain-function spelling
 * of DATE_SUB(CURDATE(), INTERVAL n DAY); the SQLite shims (curdate/
 * subdate/concat) live in tests/TestCase.php.
 */
class ConsultationTest extends TestCase
{
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

        // Shapes mirror the live table (Phase 8 information_schema probe).
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

        // Needed for the patient combobox query (LEFT JOIN student_details/employee_details)
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
    }

    // ---------------------------------------------------------------------
    // Staff page: render, rows, preselect (original 104-213)
    // ---------------------------------------------------------------------

    public function test_staff_page_renders_toolbar_modal_and_empty_state(): void
    {
        $staff = $this->makeStaff();
        $this->makePatient('Juan', 'Dela Cruz', 'student');
        $this->makePatient('Maria', 'Santos', 'employee');

        $response = $this->actingAs($staff)->get('/staff/consultations');

        $response->assertOk();
        $response->assertSee('Consultations');
        // Toolbar: client-side search, filter select, reset, new button.
        $response->assertSee('id="consultationsSearch"', false);
        $response->assertSee('<option value="all" selected>', false);
        $response->assertSee('data-search-trigger="#consultationsSearch"', false);
        $response->assertSee('>Reset</a>', false);
        $response->assertSee('New Consultation');
        // Table head + empty state.
        $response->assertSee('<th class="text-end">Actions</th>', false);
        $response->assertSee('<td colspan="5" class="text-center text-muted py-4">No consultations found.</td>', false);
        // Modal in its New state with patient combobox and datalist.
        $response->assertSee('id="consultationModal"', false);
        $response->assertSee('New Consultation');
        $response->assertSee('data-patient-combobox="#consultationPatientId"', false);
        $response->assertSee('list="consultationPatientSuggestions"', false);
        $response->assertSee('<option value="Juan Dela Cruz', false);
        $response->assertSee('<option value="Maria Santos', false);
        $response->assertSee('_token'); // @csrf (approved convention)
        $response->assertSee('data-swal-add="consultation"', false);
        $response->assertDontSee('data-swal-save="consultation"', false);
        $response->assertDontSee('>Cancel</a>', false);
        // No errors and no edit => no auto-open; reset-on-hide script stays.
        $response->assertDontSee('new bootstrap.Modal(modal).show()');
        $response->assertSee("modal.addEventListener('hidden.bs.modal'", false);
        $this->assertSame(1, substr_count($response->getContent(), 'class="modal fade"'));
    }

    public function test_rows_render_truncated_cells_actions_and_patient_preselect(): void
    {
        $staff = $this->makeStaff();
        $juan = $this->makePatient('Juan', 'Dela Cruz', 'student');
        $maria = $this->makePatient('Maria', 'Santos', 'employee');

        $complaint = str_repeat('headache and fever ', 4); // 76 chars -> trimmed at 40
        $diagnosis = str_repeat('viral infection ', 4);    // 68 chars -> trimmed at 40
        $id = $this->makeConsultation($juan->id, $staff->id, $complaint, $diagnosis);

        $response = $this->actingAs($staff)->get('/staff/consultations');

        $response->assertOk();
        $response->assertSee(fmt_dt(DB::table('consultations')->value('consultation_date'), 'M d, Y g:i A'));
        $response->assertSee('Juan Dela Cruz');
        $response->assertSee(mb_strimwidth($complaint, 0, 40, '…'), false);
        $response->assertSee(mb_strimwidth($diagnosis, 0, 40, '…'), false);
        $response->assertSee('?edit='.$id, false);
        $response->assertSee('?action=delete&id='.$id, false);
        $response->assertSee('Delete this consultation?');
        $response->assertDontSee('No consultations found.');

        // ?patient_id= preselect: Maria's hidden input carries the value, Juan's does not.
        $pre = $this->actingAs($staff)->get('/staff/consultations?patient_id='.$maria->id);
        $pre->assertOk();
        $pre->assertSee('id="consultationPatientId" name="patient_id" value="'.$maria->id.'"', false);
        $pre->assertDontSee('value="'.$juan->id.'"', false);
    }

    // ---------------------------------------------------------------------
    // Save handler (original lines 12-43)
    // ---------------------------------------------------------------------

    public function test_valid_post_saves_audits_and_notifies_the_patient_only(): void
    {
        $staff = $this->makeStaff();
        $juan = $this->makePatient('Juan', 'Dela Cruz', 'student');

        $response = $this->actingAs($staff)->post('/staff/consultations', [
            'patient_id' => $juan->id,
            'complaint' => ' Persistent cough ',
            'blood_pressure' => '120/80',
            'temperature' => '36.5',
            'pulse' => '80',
            'respiration' => '18',
            'weight_kg' => '65.5',
            'height_cm' => '170.25',
            'diagnosis' => 'Colds',
            'treatment' => 'Rest and fluids',
            'notes' => 'Follow up in 3 days',
        ]);

        $response->assertRedirect(route('staff.consultations'));
        $response->assertSessionHas('success', 'Consultation saved.');

        $row = DB::table('consultations')->first();
        $this->assertNotNull($row);
        $this->assertSame($staff->id, (int) $row->staff_id);
        $this->assertSame($juan->id, (int) $row->patient_id);
        $this->assertSame('Persistent cough', $row->complaint); // trimmed
        $this->assertSame('120/80', $row->blood_pressure);
        $this->assertSame(36.5, (float) $row->temperature);
        $this->assertSame(80, (int) $row->pulse);
        $this->assertSame(18, (int) $row->respiration);
        $this->assertSame(65.5, (float) $row->weight_kg);
        $this->assertSame(170.25, (float) $row->height_cm);
        $this->assertSame('Colds', $row->diagnosis);

        // Audit row for the create (log_action port) ...
        $audit = DB::table('audit_logs')->where('action', 'create_consultation')->first();
        $this->assertNotNull($audit);
        $this->assertSame($staff->id, (int) $audit->user_id);
        $this->assertSame('consultations', $audit->table_name);
        $this->assertSame((int) $row->id, (int) $audit->record_id);
        $this->assertSame("Consultation for patient #{$juan->id}", $audit->details);

        // Feature 2: consultation_new is addressed to the patient and nobody
        // else - the recording staff member is not a recipient - and is
        // deduplicated on the consultation id.
        $note = DB::table('notifications')->first();
        $this->assertNotNull($note);
        $this->assertSame((int) $juan->id, (int) $note->user_id);
        $this->assertSame('consultation_new', $note->type);
        $this->assertSame((int) $row->id, (int) $note->reference_id);
        $this->assertSame(0, DB::table('notifications')->where('user_id', $staff->id)->count());

        // PRG: the follow-up GET shows the new row. The original's set_flash
        // slot is write-only (no renderer exists - see partials/flash.blade.php),
        // so 'Consultation saved.' stays in the session but renders nothing.
        $this->actingAs($staff)->get('/staff/consultations')
            ->assertOk()
            ->assertDontSee('Consultation saved.')
            ->assertSee('Juan Dela Cruz');

        // Blank numeric fields store NULL (original lines 20-24: '' => null).
        $this->actingAs($staff)->post('/staff/consultations', [
            'patient_id' => $juan->id,
            'complaint' => 'No vitals taken',
            'blood_pressure' => '',
            'temperature' => '',
            'pulse' => '',
            'respiration' => '',
            'weight_kg' => '',
            'height_cm' => '',
        ])->assertRedirect(route('staff.consultations'));

        $second = DB::table('consultations')->orderBy('id', 'desc')->first();
        // bp is a trim('') field in the original (line 21) -> empty string;
        // the five !== '' numeric fields -> null.
        $this->assertSame('', $second->blood_pressure);
        $this->assertNull($second->temperature);
        $this->assertNull($second->pulse);
        $this->assertNull($second->weight_kg);
    }

    public function test_invalid_post_collects_both_errors_in_order_without_writing(): void
    {
        $staff = $this->makeStaff();
        $this->makePatient('Juan', 'Dela Cruz', 'student');

        $response = $this->actingAs($staff)->post('/staff/consultations', [
            'patient_id' => '',
            'complaint' => '',
        ]);

        $response->assertOk();
        $response->assertSee('<div class="alert alert-danger py-2">Please select a patient.</div>', false);
        $response->assertSee('<div class="alert alert-danger py-2">Complaint is required.</div>', false);
        $this->assertSame(2, substr_count($response->getContent(), 'alert alert-danger py-2'));
        $response->assertSee('New Consultation');
        // Modal auto-opens when the re-render carries errors.
        $response->assertSee('new bootstrap.Modal(modal).show()', false);
        $this->assertSame(0, DB::table('consultations')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());

        // Patient chosen but complaint blank => only the complaint message.
        $only = $this->actingAs($staff)->post('/staff/consultations', [
            'patient_id' => 1,
            'complaint' => '',
        ]);
        $only->assertOk();
        $only->assertSee('Complaint is required.');
        $only->assertDontSee('Please select a patient.');
        $this->assertSame(0, DB::table('consultations')->count());
    }

    public function test_numeric_field_quirks_blank_is_null_but_missing_key_is_zero(): void
    {
        $staff = $this->makeStaff();
        $juan = $this->makePatient('Juan', 'Dela Cruz', 'student');

        $payload = [
            'patient_id' => $juan->id,
            'complaint' => 'Quirk check',
            'pulse' => '', // blank => NULL
            // 'temperature' key omitted entirely: the original's
            // $_POST['temperature'] !== '' evaluates null !== '' and casts
            // to 0.0 - a crafted-POST quirk kept for parity.
        ];
        $this->actingAs($staff)->post('/staff/consultations', $payload)
            ->assertRedirect(route('staff.consultations'));

        $row = DB::table('consultations')->first();
        $this->assertNull($row->pulse);
        $this->assertSame(0.0, (float) $row->temperature);
    }

    // ---------------------------------------------------------------------
    // Edit prefill + delete (original lines 45-58)
    // ---------------------------------------------------------------------

    public function test_edit_prefills_the_modal_and_missing_edit_falls_back_to_new(): void
    {
        $staff = $this->makeStaff();
        $juan = $this->makePatient('Juan', 'Dela Cruz', 'student');
        $id = $this->makeConsultation($juan->id, $staff->id, 'Fever', 'Flu', [
            'blood_pressure' => '110/70',
            'temperature' => 36.2,
        ]);

        $edit = $this->actingAs($staff)->get('/staff/consultations?edit='.$id);
        $edit->assertOk();
        $edit->assertSee('Edit Consultation');
        $edit->assertSee('data-swal-save="consultation"', false);
        $edit->assertSee('>Cancel</a>', false);
        $edit->assertSee('>Fever</textarea>', false);
        $edit->assertSee('value="110/70"', false);
        $edit->assertSee('id="consultationPatientId" name="patient_id" value="'.$juan->id.'"', false);
        // Auto-opens because $edit is truthy (original line 216).
        $edit->assertSee('new bootstrap.Modal(modal).show()', false);

        // A missing row is falsy => plain New state, exactly like the
        // original's fetch() returning false.
        $missing = $this->actingAs($staff)->get('/staff/consultations?edit=999');
        $missing->assertOk();
        $missing->assertSee('New Consultation');
        $missing->assertSee('data-swal-add="consultation"', false);
        $missing->assertDontSee('>Cancel</a>', false);
        $missing->assertDontSee('new bootstrap.Modal(modal).show()');
    }

    public function test_delete_via_get_logs_audit_flashes_and_tolerates_missing_ids(): void
    {
        $staff = $this->makeStaff();
        $juan = $this->makePatient('Juan', 'Dela Cruz', 'student');
        $keep = $this->makeConsultation($juan->id, $staff->id, 'Keep me');
        $gone = $this->makeConsultation($juan->id, $staff->id, 'Delete me');

        $response = $this->actingAs($staff)->get('/staff/consultations?action=delete&id='.$gone);

        $response->assertRedirect(route('staff.consultations'));
        $response->assertSessionHas('success', 'Consultation deleted.');
        $this->assertNull(DB::table('consultations')->find($gone));
        $this->assertNotNull(DB::table('consultations')->find($keep));
        $audit = DB::table('audit_logs')->where('action', 'delete_consultation')->first();
        $this->assertNotNull($audit);
        $this->assertSame("Deleted consultation #$gone", $audit->details);

        // Original quirk: the delete path logs even when no row matched ...
        $this->actingAs($staff)->get('/staff/consultations?action=delete&id=999')
            ->assertRedirect(route('staff.consultations'));
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'delete_consultation')->count());
        $this->assertSame(1, DB::table('consultations')->count());

        // ... but id=0/absent skips the handler and simply renders.
        $this->actingAs($staff)->get('/staff/consultations?action=delete')
            ->assertOk()
            ->assertSee('Keep me');
        $this->assertSame(1, DB::table('consultations')->count());
    }

    // ---------------------------------------------------------------------
    // History: filters, raw-filter split, search, limit (original 71-100)
    // ---------------------------------------------------------------------

    public function test_filter_windows_are_rolling_and_invalid_filter_splits_query_and_select(): void
    {
        $staff = $this->makeStaff();
        $juan = $this->makePatient('Juan', 'Dela Cruz', 'student');
        $this->makeConsultation($juan->id, null, 'Row today', null, [], date('Y-m-d H:i:s'));
        $this->makeConsultation($juan->id, null, 'Row 3d', null, [], date('Y-m-d H:i:s', strtotime('-3 days')));
        $this->makeConsultation($juan->id, null, 'Row 10d', null, [], date('Y-m-d H:i:s', strtotime('-10 days')));
        $this->makeConsultation($juan->id, null, 'Row 40d', null, [], date('Y-m-d H:i:s', strtotime('-40 days')));

        $today = $this->actingAs($staff)->get('/staff/consultations?filter=today');
        $today->assertSee('Row today');
        $today->assertDontSee('Row 3d');
        $today->assertSee('value="today" selected>', false);

        $week = $this->actingAs($staff)->get('/staff/consultations?filter=week');
        $week->assertSee('Row today');
        $week->assertSee('Row 3d');
        $week->assertDontSee('Row 10d');
        $week->assertDontSee('Row 40d');

        $month = $this->actingAs($staff)->get('/staff/consultations?filter=month');
        $month->assertSee('Row 10d');
        $month->assertDontSee('Row 40d');

        // Invalid value: the query collapses to 'all' (4 rows) while the
        // <select> reads the RAW parameter, so NO option is selected -
        // the original's split between lines 74-77 and 174-177.
        $bad = $this->actingAs($staff)->get('/staff/consultations?filter=xyz');
        $bad->assertOk();
        $bad->assertSee('Row today');
        $bad->assertSee('Row 40d');
        $bad->assertDontSee('selected');
    }

    public function test_search_matches_patient_names_complaint_and_diagnosis(): void
    {
        $staff = $this->makeStaff();
        $juan = $this->makePatient('Juan', 'Dela Cruz', 'student');
        $maria = $this->makePatient('Maria', 'Santos', 'employee');
        $this->makeConsultation($juan->id, null, 'Persistent cough', 'Viral infection');
        $this->makeConsultation($maria->id, null, 'Back pain', 'Muscle strain');

        $q = fn (string $term) => $this->actingAs($staff)->get('/staff/consultations?q='.urlencode($term))->assertOk();

        $q('Juan')->assertSee('Persistent cough')->assertDontSee('Back pain');
        $q('Santos')->assertSee('Back pain')->assertDontSee('Persistent cough');
        $q('cough')->assertSee('Viral infection')->assertDontSee('Muscle strain');
        $q('Muscle')->assertSee('Back pain')->assertDontSee('Persistent cough');
        $q('zzz-nothing')->assertSee('No consultations found.');
    }

    public function test_history_is_capped_at_100_rows(): void
    {
        $staff = $this->makeStaff();
        $juan = $this->makePatient('Juan', 'Dela Cruz', 'student');
        for ($i = 0; $i < 105; $i++) {
            $this->makeConsultation($juan->id, null, "Row $i");
        }

        $response = $this->actingAs($staff)->get('/staff/consultations');
        $response->assertOk();
        $this->assertSame(100, substr_count($response->getContent(), '?edit='));
    }

    // ---------------------------------------------------------------------
    // user/my_consultations.php
    // ---------------------------------------------------------------------

    public function test_user_history_renders_only_own_rows_with_conditional_chips(): void
    {
        $staff = $this->makeStaff();
        $student = $this->makePatient('Juan', 'Dela Cruz', 'student');
        $employee = $this->makePatient('Maria', 'Santos', 'employee');

        // Someone else's row must never show on Juan's page.
        $this->makeConsultation($employee->id, $staff->id, 'Employee complaint');

        $empty = $this->actingAs($student)->get('/user/my_consultations');
        $empty->assertOk();
        $empty->assertSee('Consultation records are shown here for quick review.');
        $empty->assertSee('You have no consultation records yet.');
        $empty->assertSee('My Activity');
        // Feature 7: My Consultations/My Certificates/My Loans sidebar items
        // removed; My Activity is the consolidated view.
        $empty->assertSee('/user/my_requests'); // active sidebar link

        // Attending staff name resolves through profiles; staff_id NULL => em dash.
        $withStaff = $this->makeConsultation(
            $student->id, $staff->id, 'Older complaint', 'Older dx', ['consultation_date' => date('Y-m-d H:i:s', strtotime('-2 days'))],
        );
        $noStaff = $this->makeConsultation(
            $student->id, null, 'Newest complaint', null,
            ['treatment' => 'Rest', 'blood_pressure' => '', 'temperature' => 36.5, 'pulse' => 0, 'weight_kg' => 0, 'height_cm' => 170],
        );

        $response = $this->actingAs($student)->get('/user/my_consultations');
        $response->assertOk();
        $response->assertSee('Attending: Clinic Staff');
        $response->assertSee('Attending: —');
        $response->assertSee('Complaint:');
        $response->assertSee('Newest complaint');
        $response->assertSee('Older complaint');
        // Newest first.
        $this->assertTrue(
            strpos($response->getContent(), 'Newest complaint') < strpos($response->getContent(), 'Older complaint'),
        );
        // Conditional chips: diagnosis absent on the newest row, treatment
        // present; truthy checks hide ''/0 while temperature's null check
        // keeps 36.5 (and would keep 0).
        $response->assertSee('Treatment:', false);
        $response->assertDontSee('BP:');
        $response->assertSee('Temp: 36.5°C', false);
        $response->assertDontSee('Pulse:');
        $response->assertDontSee('Weight:');
        $response->assertSee('Height: 170 cm', false);
        // Never shows the employee's row.
        $response->assertDontSee('Employee complaint');
    }

    public function test_authz_for_both_pages_matches_require_login(): void
    {
        $staff = $this->makeStaff();
        $student = $this->makePatient('Juan', 'Dela Cruz', 'student');
        $admin = User::create([
            'username' => 'admin', 'email' => 'admin@school.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => 'admin', 'account_type' => 'employee', 'status' => 'active',
        ]);

        $this->get('/staff/consultations')->assertRedirect(route('login'));
        $this->get('/user/my_consultations')->assertRedirect(route('login'));

        $this->actingAs($student)->get('/staff/consultations')->assertRedirect(route('user.dashboard'));
        $this->actingAs($admin)->get('/staff/consultations')->assertRedirect(route('admin.dashboard'));

        $this->actingAs($staff)->get('/user/my_consultations')->assertRedirect(route('staff.dashboard'));
        $this->actingAs($student)->get('/user/my_consultations')->assertOk();
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

        Profile::create(['user_id' => $user->id, 'first_name' => 'Clinic', 'last_name' => 'Staff']);

        return $user->fresh();
    }

    private function makePatient(string $first, string $last, string $type): User
    {
        $user = User::create([
            'username' => strtolower($first.$last),
            'email' => strtolower($first.$last).'@school.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => 'user',
            'account_type' => $type,
            'status' => 'active',
        ]);

        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last]);

        return $user->fresh();
    }

    private function makeConsultation(
        int $patientId,
        ?int $staffId,
        string $complaint = 'Complaint',
        ?string $diagnosis = null,
        array $extra = [],
        ?string $date = null,
    ): int {
        return DB::table('consultations')->insertGetId($extra + [
            'patient_id' => $patientId,
            'staff_id' => $staffId,
            'complaint' => $complaint,
            'diagnosis' => $diagnosis,
            'consultation_date' => $date ?? date('Y-m-d H:i:s'),
        ]);
    }
}

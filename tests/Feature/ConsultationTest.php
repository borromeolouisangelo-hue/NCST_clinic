<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use App\Services\ConsultationImages;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
            $table->string('diagnosis_image')->nullable();
            $table->string('treatment_image')->nullable();
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
        // Toolbar: client-side search, filter select, new button. The
        // redesign drops the Reset link from the module pages' toolbars.
        $response->assertSee('id="consultationsSearch"', false);
        $response->assertSee('<option value="all" selected>', false);
        $response->assertSee('data-search-trigger="#consultationsSearch"', false);
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
        $response->assertSee('?action=delete&amp;id='.$id, false);
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

    /**
     * Module histories feature: the module page is only the 10-row recent
     * preview - the complete, paginated history lives behind the View All
     * action at /staff/history?tab=consultations (100 records per page).
     */
    public function test_module_previews_10_newest_and_history_paginates_at_100(): void
    {
        $staff = $this->makeStaff();
        $juan = $this->makePatient('Juan', 'Dela Cruz', 'student');
        for ($i = 0; $i < 105; $i++) {
            $this->makeConsultation($juan->id, null, "Row $i");
        }

        // Recent preview: exactly the 10 newest rows plus the View All link.
        $module = $this->actingAs($staff)->get('/staff/consultations')->assertOk();
        $this->assertSame(10, substr_count($module->getContent(), '?edit='));
        $module->assertSee(route('staff.history', ['tab' => 'consultations']));

        // History: shared table markup, 100 rows per page, back link + nav.
        $page1 = $this->actingAs($staff)->get('/staff/history?tab=consultations')->assertOk();
        $this->assertSame(100, substr_count($page1->getContent(), '?edit='));
        $page1->assertSee('Consultations History')
            ->assertSee('Consultations history pagination', false);

        $page2 = $this->actingAs($staff)->get('/staff/history?tab=consultations&page=2')->assertOk();
        $this->assertSame(5, substr_count($page2->getContent(), '?edit='));
    }

    /**
     * The history toolbar's search box and date filter must narrow the rows
     * server-side and survive pagination: every page link carries ?q= and
     * ?filter= forward, a junk value collapses to 'all' for both the
     * dropdown and the query, and a search matching nothing shows the empty
     * state instead of a blank page.
     */
    public function test_history_search_and_date_filter_are_preserved_across_pagination(): void
    {
        $staff = $this->makeStaff();
        $juan = $this->makePatient('Juan', 'Dela Cruz', 'student');
        for ($i = 0; $i < 105; $i++) {
            $this->makeConsultation($juan->id, null, "Row $i");
        }

        // All 105 rows are today's: the filtered set still paginates and
        // both filters appear in every page link.
        $response = $this->actingAs($staff)
            ->get('/staff/history?tab=consultations&q=Row&filter=today')
            ->assertOk();
        $this->assertSame(100, substr_count($response->getContent(), '?edit='));
        $response->assertSee('q=Row', false)
            ->assertSee('filter=today', false)
            ->assertSee('<option value="today" selected>', false);

        // A junk value falls back to 'all' for both the dropdown and rows.
        $junk = $this->actingAs($staff)->get('/staff/history?tab=consultations&filter=banana')->assertOk();
        $this->assertSame(100, substr_count($junk->getContent(), '?edit='));
        $junk->assertSee('<option value="all" selected>', false);

        // A search matching one row narrows to that row; no match shows
        // the shared empty state.
        $one = $this->actingAs($staff)->get('/staff/history?tab=consultations&q=Row%20104')->assertOk();
        $this->assertSame(1, substr_count($one->getContent(), '?edit='));
        $this->actingAs($staff)->get('/staff/history?tab=consultations&q=zzz-nothing')->assertOk()
            ->assertSee('No records found.');
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
    // Image attachments - diagnosis / treatment (validated upload, private
    // disk storage, authorized viewing, cleanup on delete)
    // ---------------------------------------------------------------------

    public function test_valid_image_uploads_are_stored_on_the_private_disk_and_saved(): void
    {
        Storage::fake('local');
        $staff = $this->makeStaff();
        $juan = $this->makePatient('Juan', 'Dela Cruz', 'student');

        $response = $this->actingAs($staff)->post('/staff/consultations', [
            'patient_id' => $juan->id,
            'complaint' => 'Rash with photo',
            'diagnosis' => 'Contact dermatitis',
            'treatment' => 'Topical cream',
            'diagnosis_image' => UploadedFile::fake()->createWithContent('skin.png', $this->png(8, 8)),
            'treatment_image' => UploadedFile::fake()->createWithContent('after.png', $this->png(8, 8)),
        ]);

        $response->assertRedirect(route('staff.consultations'));
        $response->assertSessionHas('success', 'Consultation saved.');

        $row = DB::table('consultations')->first();
        $this->assertNotNull($row);
        $this->assertMatchesRegularExpression('/^diagnosis_[A-Za-z0-9]{40}\.png$/', (string) $row->diagnosis_image);
        $this->assertMatchesRegularExpression('/^treatment_[A-Za-z0-9]{40}\.png$/', (string) $row->treatment_image);
        // Diagnosis and treatment attachments stay separate files.
        $this->assertNotSame($row->diagnosis_image, $row->treatment_image);

        // The bytes persist on the PRIVATE local disk - never under public/.
        Storage::disk('local')->assertExists(ConsultationImages::DIRECTORY.'/'.$row->diagnosis_image);
        Storage::disk('local')->assertExists(ConsultationImages::DIRECTORY.'/'.$row->treatment_image);
        $this->assertFileDoesNotExist(public_path(ConsultationImages::DIRECTORY.'/'.$row->diagnosis_image));
    }

    public function test_jpeg_and_webp_uploads_are_accepted(): void
    {
        Storage::fake('local');
        $staff = $this->makeStaff();
        $juan = $this->makePatient('Juan', 'Dela Cruz', 'student');

        $this->actingAs($staff)->post('/staff/consultations', [
            'patient_id' => $juan->id,
            'complaint' => 'Wound check',
            'diagnosis_image' => UploadedFile::fake()->create('scan.jpg', 4, 'image/jpeg'),
            'treatment_image' => UploadedFile::fake()->create('wound.webp', 4, 'image/webp'),
        ])->assertRedirect(route('staff.consultations'));

        $row = DB::table('consultations')->first();
        $this->assertMatchesRegularExpression('/\.jpg$/', (string) $row->diagnosis_image);
        $this->assertMatchesRegularExpression('/\.webp$/', (string) $row->treatment_image);
        Storage::disk('local')->assertExists(ConsultationImages::DIRECTORY.'/'.$row->diagnosis_image);
        Storage::disk('local')->assertExists(ConsultationImages::DIRECTORY.'/'.$row->treatment_image);
    }

    public function test_saving_without_attachments_keeps_both_columns_null(): void
    {
        Storage::fake('local');
        $staff = $this->makeStaff();
        $juan = $this->makePatient('Juan', 'Dela Cruz', 'student');

        $this->actingAs($staff)->post('/staff/consultations', [
            'patient_id' => $juan->id,
            'complaint' => 'No photos',
        ])->assertRedirect(route('staff.consultations'));

        $row = DB::table('consultations')->first();
        $this->assertNull($row->diagnosis_image);
        $this->assertNull($row->treatment_image);
        $this->assertSame([], Storage::disk('local')->allFiles(ConsultationImages::DIRECTORY));
    }

    public function test_non_image_attachment_is_rejected_without_writing_anything(): void
    {
        Storage::fake('local');
        $staff = $this->makeStaff();
        $juan = $this->makePatient('Juan', 'Dela Cruz', 'student');

        $response = $this->actingAs($staff)->post('/staff/consultations', [
            'patient_id' => $juan->id,
            'complaint' => 'Note attached',
            'diagnosis_image' => UploadedFile::fake()->create('notes.txt', 4, 'text/plain'),
        ]);

        $response->assertOk();
        $response->assertSee('Diagnosis image must be a JPG, JPEG, PNG or WEBP file.');
        $this->assertSame(0, DB::table('consultations')->count());
        $this->assertSame([], Storage::disk('local')->allFiles(ConsultationImages::DIRECTORY));
    }

    public function test_oversized_attachment_is_rejected_with_the_size_message(): void
    {
        Storage::fake('local');
        $staff = $this->makeStaff();
        $juan = $this->makePatient('Juan', 'Dela Cruz', 'student');

        $big = $this->png(8, 8).str_repeat(' ', ConsultationImages::MAX_KILOBYTES * 1024 + 1);
        $response = $this->actingAs($staff)->post('/staff/consultations', [
            'patient_id' => $juan->id,
            'complaint' => 'Huge photo',
            'treatment_image' => UploadedFile::fake()->createWithContent('huge.png', $big),
        ]);

        $response->assertOk();
        $response->assertSee('Treatment image must not be larger than 5 MB.');
        $this->assertSame(0, DB::table('consultations')->count());
        $this->assertSame([], Storage::disk('local')->allFiles(ConsultationImages::DIRECTORY));
    }

    public function test_attachment_route_serves_staff_and_owner_and_blocks_everyone_else(): void
    {
        Storage::fake('local');
        $staff = $this->makeStaff();
        $juan = $this->makePatient('Juan', 'Dela Cruz', 'student');
        $pedro = $this->makePatient('Pedro', 'Cruz', 'student');

        $this->actingAs($staff)->post('/staff/consultations', [
            'patient_id' => $juan->id,
            'complaint' => 'With photo',
            'diagnosis_image' => UploadedFile::fake()->createWithContent('skin.png', $this->png(8, 8)),
        ])->assertRedirect(route('staff.consultations'));

        $row = DB::table('consultations')->first();
        $url = route('consultations.attachment', ['id' => $row->id, 'type' => 'diagnosis']);

        // Staff may view any consultation's image, streamed as the image.
        $this->actingAs($staff)->get($url)->assertOk()->assertHeader('content-type', 'image/png');

        // The owning patient may view their own.
        $this->actingAs($juan)->get($url)->assertOk()->assertHeader('content-type', 'image/png');

        // Another patient may not.
        $this->actingAs($pedro)->get($url)->assertForbidden();

        // Unknown consultation, unknown type, and a type with no stored
        // file all 404 - nothing leaks existence.
        $this->actingAs($staff)->get(route('consultations.attachment', ['id' => 9999, 'type' => 'diagnosis']))->assertNotFound();
        $this->actingAs($staff)->get(route('consultations.attachment', ['id' => $row->id, 'type' => 'invoice']))->assertNotFound();
        $this->actingAs($staff)->get(route('consultations.attachment', ['id' => $row->id, 'type' => 'treatment']))->assertNotFound();
    }

    public function test_form_declares_multipart_so_browsers_actually_upload_the_files(): void
    {
        $staff = $this->makeStaff();

        // Without enctype="multipart/form-data" real browsers silently drop
        // file inputs from the POST (Laravel's test client bypasses enctype,
        // so only this render assertion + the browser check catch it).
        $this->actingAs($staff)->get('/staff/consultations')
            ->assertOk()
            ->assertSee('enctype="multipart/form-data"', false);
    }

    public function test_attachment_route_sends_guests_to_login(): void
    {
        $staff = $this->makeStaff();
        $juan = $this->makePatient('Juan', 'Dela Cruz', 'student');
        $id = $this->makeConsultation($juan->id, $staff->id, 'Guest probe');

        // No actingAs anywhere in this test: a plain guest request must be
        // redirected to login by the auth middleware (actingAs latches the
        // user for every later request, so this cannot live in the test
        // above).
        $this->get(route('consultations.attachment', ['id' => $id, 'type' => 'diagnosis']))
            ->assertRedirect(route('login'));
    }

    public function test_attachment_links_appear_in_staff_history_and_patient_view_only_when_present(): void
    {
        Storage::fake('local');
        $staff = $this->makeStaff();
        $juan = $this->makePatient('Juan', 'Dela Cruz', 'student');

        $this->actingAs($staff)->post('/staff/consultations', [
            'patient_id' => $juan->id,
            'complaint' => 'With photo',
            'diagnosis' => 'Cough',
            'treatment' => 'Rest',
            'diagnosis_image' => UploadedFile::fake()->createWithContent('skin.png', $this->png(8, 8)),
            'treatment_image' => UploadedFile::fake()->createWithContent('after.png', $this->png(8, 8)),
        ])->assertRedirect(route('staff.consultations'));

        $withImages = DB::table('consultations')->first();
        $diagUrl = route('consultations.attachment', ['id' => $withImages->id, 'type' => 'diagnosis']);
        $treatUrl = route('consultations.attachment', ['id' => $withImages->id, 'type' => 'treatment']);

        // Staff list shows both links (the history-table partial is shared
        // with staff/history, so both pages get them).
        $this->actingAs($staff)->get('/staff/consultations')
            ->assertOk()
            ->assertSee($diagUrl, false)
            ->assertSee($treatUrl, false);
        $this->actingAs($staff)->get('/staff/history?tab=consultations')
            ->assertOk()
            ->assertSee($diagUrl, false);

        // The patient's own history shows them too.
        $this->actingAs($juan)->get('/user/my_consultations')
            ->assertOk()
            ->assertSee('View image');

        // A consultation without images renders no attachment links at all.
        $plain = $this->makeConsultation($juan->id, $staff->id, 'Plain visit');
        $this->actingAs($staff)->get('/staff/consultations')
            ->assertOk()
            ->assertDontSee(route('consultations.attachment', ['id' => $plain, 'type' => 'diagnosis']), false);
    }

    public function test_deleting_a_consultation_removes_its_stored_images(): void
    {
        Storage::fake('local');
        $staff = $this->makeStaff();
        $juan = $this->makePatient('Juan', 'Dela Cruz', 'student');

        $this->actingAs($staff)->post('/staff/consultations', [
            'patient_id' => $juan->id,
            'complaint' => 'With photo',
            'diagnosis_image' => UploadedFile::fake()->createWithContent('skin.png', $this->png(8, 8)),
        ])->assertRedirect(route('staff.consultations'));

        $row = DB::table('consultations')->first();
        Storage::disk('local')->assertExists(ConsultationImages::DIRECTORY.'/'.$row->diagnosis_image);

        $this->actingAs($staff)->get('/staff/consultations?action=delete&id='.$row->id)
            ->assertRedirect(route('staff.consultations'));

        $this->assertSame(0, DB::table('consultations')->count());
        Storage::disk('local')->assertMissing(ConsultationImages::DIRECTORY.'/'.$row->diagnosis_image);
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

    /**
     * Minimal valid PNG of the given size, built without GD (the same
     * hand-rolled header + zlib bitmap ProfileTest uses): this environment
     * has no GD extension, so UploadedFile::fake()->image() is unavailable,
     * while getimagesize()/finfo still see real pixels.
     */
    private function png(int $width, int $height): string
    {
        $raw = str_repeat("\x00".str_repeat("\xff\xff\xff", $width), $height);

        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        };

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress($raw))
            .$chunk('IEND', '');
    }
}

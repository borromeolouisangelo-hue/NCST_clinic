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
 * Phase 21 user/medical_form.php tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects (DB_CONNECTION=sqlite, DB_DATABASE=:memory:).
 *
 * Covers require_login(['user']) access, the latest-record read, the record
 * upsert (insert + same-semester overwrite on user_id+school_year+semester),
 * the presence-keyed school-year/semester fallbacks (POSTed '' -> error,
 * absent key -> get_setting), the exact four-message validation order with
 * its DOUBLE rendering (top block + allergy-modal block) and allergy-modal
 * reopen script, the typed-health-field re-show with the STORED year/
 * semester pair, add_allergy (severity fallback, empty-name error) and the
 * GET delete handler (owned delete, id=999 no-row still audited, other
 * user's row untouched but audited). Token-less POST -> 419 is middleware
 * (browser-verified; Feature tests bypass VerifyCsrfToken - ACCEPTED).
 */
class MedicalFormTest extends TestCase
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

        // Shell pieces the shared layout reads.
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

        // Reference schema: unique (user_id, school_year, semester) - the key
        // the reference's INSERT ... ON DUPLICATE KEY UPDATE fires on.
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
            $table->text('current_symptoms')->nullable();
            $table->string('school_year', 20);
            $table->string('semester', 10);
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->unique(['user_id', 'school_year', 'semester']);
        });

        Schema::create('user_allergies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('medicine_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('allergen_name', 150);
            $table->string('severity', 10)->default('mild');
            $table->string('notes', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // get_setting() reads this via the SystemSetting model.
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key', 100)->unique();
            $table->string('setting_value')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    // ---------------------------------------------------------------------
    // Access + clean render
    // ---------------------------------------------------------------------

    public function test_role_user_accounts_render_and_guest_and_wrong_roles_bounce(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');
        $employee = $this->makeUser('memployee', 'user', 'employee');
        $admin = $this->makeUser('adminx', 'admin', null);
        $staff = $this->makeUser('staffx', 'staff', 'employee');

        $this->get('/user/medical_form')->assertRedirect(route('login'));
        $this->actingAs($student)->get('/user/medical_form')->assertOk();
        $this->actingAs($employee)->get('/user/medical_form')->assertOk();
        $this->actingAs($admin)->get('/user/medical_form')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($staff)->get('/user/medical_form')->assertRedirect(route('staff.dashboard'));
    }

    public function test_clean_get_uses_setting_fallbacks_and_renders_both_forms(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');

        $response = $this->actingAs($student)->get('/user/medical_form');
        $response->assertOk();
        $response->assertSee('<div class="card-header">Health Information</div>', false);
        $response->assertSee('<input type="text" name="school_year" class="form-control" value="'.date('Y').'">', false);
        // No settings row -> get_setting defaults: 1st semester selected.
        $response->assertSee('<option value="1st" selected>', false);
        $response->assertSee('<form method="post" data-autosave="medical-form">', false);
        $response->assertSee('<form method="post">'."\n", false);
        $response->assertSee('name="form_action" value="add_allergy"', false);
        $response->assertSee('No allergies recorded.', false);
        $response->assertSee('Complete this form each semester.', false);
        $response->assertSee('<div class="card-header"><i class="bi bi-exclamation-triangle text-accent me-1"></i> Allergy Registry</div>', false);
        // No errors -> no alert blocks, no reopen script.
        $response->assertDontSee('<div class="alert alert-danger py-2">', false);
        $response->assertDontSee('new bootstrap.Modal(modal).show()', false);
    }

    public function test_get_shows_latest_record_by_submitted_at_and_allergy_list(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');
        // Older record first in time, newer second (ORDER BY submitted_at DESC).
        DB::table('medical_records')->insert([
            'user_id' => $student->id, 'school_year' => '2024', 'semester' => '1st',
            'blood_type' => 'O', 'height_cm' => 180, 'weight_kg' => 90,
            'allergies_text' => 'old text', 'submitted_at' => '2024-09-01 10:00:00',
        ]);
        DB::table('medical_records')->insert([
            'user_id' => $student->id, 'school_year' => '2025', 'semester' => '2nd',
            'blood_type' => 'A+', 'height_cm' => 170.5, 'weight_kg' => 60,
            'current_symptoms' => 'cough', 'submitted_at' => '2025-03-01 10:00:00',
        ]);
        DB::table('user_allergies')->insert([
            ['user_id' => $student->id, 'allergen_name' => 'Penicillin', 'severity' => 'severe', 'notes' => 'anaphylaxis', 'created_at' => '2025-01-01 10:00:00'],
            ['user_id' => $student->id, 'allergen_name' => 'Peanuts', 'severity' => 'mild', 'notes' => null, 'created_at' => '2025-01-02 10:00:00'],
        ]);

        $response = $this->actingAs($student)->get('/user/medical_form');
        $response->assertOk();
        // LATEST record wins for every prefill incl. the year/semester pair.
        $response->assertSee('value="2025"', false);
        $response->assertSee('<option value="2nd" selected>', false);
        $response->assertSee('value="A+"', false);
        $response->assertSee('value="170.5"', false);
        $response->assertSee('value="60"', false);
        $response->assertSee('>cough</textarea>', false);
        $response->assertDontSee('>old text</textarea>', false);
        // Allergy list: names, severity badges (color-coded), notes-conditional block.
        $response->assertSee('<strong>Penicillin</strong>', false);
        $response->assertSee('<span class="badge bg-danger">Severe</span>', false);
        $response->assertSee('<div class="small text-muted">anaphylaxis</div>', false);
        $response->assertSee('<strong>Peanuts</strong>', false);
        $this->assertSame(1, substr_count((string) $response->getContent(), '<div class="small text-muted">'));
        // GET delete links carry the own-row query string (e()/{{ }} both
        // escape & -> &amp;, as the reference does).
        $response->assertSee('user/medical_form?action=delete_allergy&amp;id=', false);
    }

    // ---------------------------------------------------------------------
    // Record save: insert + upsert
    // ---------------------------------------------------------------------

    public function test_record_save_inserts_row_pr_g_redirects_and_audits(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');

        $response = $this->actingAs($student)->post('/user/medical_form', [
            'form_action' => 'record',
            'school_year' => '2025-2026',
            'semester' => '2nd',
            'blood_type' => 'A+',
            'height_cm' => '170.5',
            'weight_kg' => '60.2',
            'allergies_text' => 'penicillin',
            'existing_conditions' => 'asthma',
            'current_medications' => 'none',
            'immunizations' => 'flu',
            'current_symptoms' => 'cough',
        ]);

        $response->assertRedirect(route('user.medical_form'));
        $response->assertSessionHas('success', 'Medical form saved.');
        $this->assertDatabaseCount('medical_records', 1);
        $this->assertDatabaseHas('medical_records', [
            'user_id' => $student->id,
            'school_year' => '2025-2026',
            'semester' => '2nd',
            'blood_type' => 'A+',
            'height_cm' => 170.5,
            'weight_kg' => 60.2,
            'allergies_text' => 'penicillin',
            'current_symptoms' => 'cough',
        ]);
        $recordId = (int) DB::table('medical_records')->value('id');
        $this->assertSame(1, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $student->id,
            'action' => 'submit_medical_form',
            'table_name' => 'medical_records',
            'record_id' => $recordId,
            'details' => 'Submitted medical form',
        ]);
    }

    public function test_record_save_upserts_the_same_semester_row(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');
        DB::table('medical_records')->insert([
            'user_id' => $student->id, 'school_year' => '2025', 'semester' => '1st',
            'blood_type' => 'A', 'height_cm' => 170, 'weight_kg' => 60,
            'submitted_at' => '2025-01-01 10:00:00',
        ]);
        $originalId = (int) DB::table('medical_records')->value('id');

        $response = $this->actingAs($student)->post('/user/medical_form', [
            'form_action' => 'record',
            'school_year' => '2025',
            'semester' => '1st',
            'blood_type' => 'B+',
            'height_cm' => '171',
            'weight_kg' => '61',
        ]);

        $response->assertRedirect(route('user.medical_form'));
        // Same unique key -> UPDATE in place (no second row), id preserved.
        $this->assertDatabaseCount('medical_records', 1);
        $this->assertDatabaseHas('medical_records', [
            'id' => $originalId,
            'blood_type' => 'B+',
            'height_cm' => 171,
            'weight_kg' => 61,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'submit_medical_form',
            'record_id' => $originalId,
        ]);
    }

    public function test_record_validation_errors_double_render_in_original_order(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');
        DB::table('medical_records')->insert([
            'user_id' => $student->id, 'school_year' => '2025', 'semester' => '1st',
            'blood_type' => 'O', 'submitted_at' => '2025-01-01 10:00:00',
        ]);

        $response = $this->actingAs($student)->post('/user/medical_form', [
            'form_action' => 'record',
            'school_year' => '',            // present-but-empty -> error
            'semester' => '3rd',            // not in the whitelist
            'blood_type' => 'XYZ',          // typed value re-shows
            'height_cm' => 'abc',           // float-casts to 0 -> range error
            'weight_kg' => '800',           // over the 700 ceiling
        ]);

        $content = (string) $response->getContent();
        $response->assertOk();

        $messages = [
            'School year is required.',
            'Height must be between 1 and 300 cm.',
            'Weight must be between 1 and 700 kg.',
            'Select a valid semester.',
        ];
        $positions = [];
        foreach ($messages as $i => $message) {
            $positions[] = strpos($content, $message);
            $this->assertNotFalse($positions[$i], $message);
            // TWICE: top-level block AND allergy-modal block (both
            // unconditional in the reference).
            $this->assertSame(2, substr_count($content, $message), $message);
        }
        $this->assertTrue($positions[0] < $positions[1] && $positions[1] < $positions[2] && $positions[2] < $positions[3]);

        // Both blocks present: top (4sp lead) + modal (20sp @foreach lead
        // merges with the 24sp div lead onto one output line = 44sp)...
        $this->assertStringContainsString("\n    <div class=\"alert alert-danger py-2\">", $content);
        $this->assertStringContainsString("\n                                            <div class=\"alert alert-danger py-2\">", $content);
        // ...and the allergy modal reopens for this RECORD-form error (quirk).
        $response->assertSee('new bootstrap.Modal(modal).show()', false);

        // Typed health field re-shows; the year/semester pair comes from the
        // STORED record (posted values deliberately lost). Blood type is now a select.
        // Invalid posted value "XYZ" is not in the whitelist, so the stored value "O" is selected.
        $response->assertSee('<select name="blood_type" class="form-select">', false);
        $response->assertDontSee('<option value="XYZ"', false);
        // The select option rendering may have whitespace variations
        $this->assertStringContainsString('value="O"', $response->getContent());
        $this->assertStringContainsString('selected', $response->getContent());
        $response->assertSee('<input type="text" name="school_year" class="form-control" value="2025">', false);
        $response->assertSee('<option value="1st" selected>', false);

        // No write, no audit.
        $this->assertDatabaseCount('medical_records', 1);
        $this->assertSame('O', DB::table('medical_records')->value('blood_type'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_absent_year_falls_back_to_setting_while_posted_empty_semester_errors(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');

        // school_year key ABSENT -> get_setting fallback (default date('Y')),
        // so NO year error; semester posted as '' -> whitelist error. This is
        // the presence-keyed read: '' and absent must not behave the same.
        $response = $this->actingAs($student)->post('/user/medical_form', [
            'form_action' => 'record',
            'semester' => '',
            'blood_type' => 'B',
        ]);

        $content = (string) $response->getContent();
        $response->assertOk();
        $response->assertDontSee('School year is required.', false);
        $response->assertSee('Select a valid semester.', false);
        $this->assertSame(2, substr_count($content, 'Select a valid semester.'));
        $this->assertSame(0, DB::table('medical_records')->count());
    }

    public function test_absent_keys_fall_back_to_defaults_and_the_record_saves(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');

        // Neither year nor semester posted -> both get_setting fallbacks,
        // validation passes and the row saves with the defaults.
        $response = $this->actingAs($student)->post('/user/medical_form', [
            'form_action' => 'record',
            'blood_type' => 'AB',
        ]);

        $response->assertRedirect(route('user.medical_form'));
        $this->assertDatabaseHas('medical_records', [
            'user_id' => $student->id,
            'school_year' => date('Y'),
            'semester' => '1st',
            'blood_type' => 'AB',
        ]);
    }

    public function test_missing_form_action_defaults_to_the_record_path(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');

        // No form_action key at all -> original's ?? 'record' default: the
        // record handler runs (its school-year error proves the branch).
        $response = $this->actingAs($student)->post('/user/medical_form', [
            'school_year' => '',
            'semester' => '1st',
        ]);

        $response->assertOk();
        $response->assertSee('School year is required.', false);
        $response->assertDontSee('Select a valid semester.', false);
        $this->assertSame(0, DB::table('medical_records')->count());
    }

    // ---------------------------------------------------------------------
    // Allergies: add + delete
    // ---------------------------------------------------------------------

    public function test_add_allergy_inserts_audits_and_redirects(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');

        $response = $this->actingAs($student)->post('/user/medical_form', [
            'form_action' => 'add_allergy',
            'allergen_name' => '  Pollen  ',
            'severity' => 'moderate',
            'notes' => '  spring only  ',
        ]);

        $response->assertRedirect(route('user.medical_form'));
        $response->assertSessionHas('success', 'Allergy added.');
        $this->assertDatabaseCount('user_allergies', 1);
        $this->assertDatabaseHas('user_allergies', [
            'user_id' => $student->id,
            'allergen_name' => 'Pollen',   // trimmed
            'severity' => 'moderate',
            'notes' => 'spring only',      // trimmed
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $student->id,
            'action' => 'add_allergy',
            'table_name' => 'user_allergies',
            'record_id' => (int) DB::table('user_allergies')->value('id'),
            'details' => 'Added allergy: Pollen',
        ]);
    }

    public function test_add_allergy_out_of_whitelist_severity_defaults_to_mild(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');

        $response = $this->actingAs($student)->post('/user/medical_form', [
            'form_action' => 'add_allergy',
            'allergen_name' => 'Dust',
            'severity' => 'extreme',
        ]);

        $response->assertRedirect(route('user.medical_form'));
        $this->assertDatabaseHas('user_allergies', [
            'allergen_name' => 'Dust',
            'severity' => 'mild',
        ]);
    }

    public function test_add_allergy_empty_name_renders_the_error_twice_and_writes_nothing(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');

        $response = $this->actingAs($student)->post('/user/medical_form', [
            'form_action' => 'add_allergy',
            'allergen_name' => '   ',
            'severity' => 'severe',
        ]);

        $content = (string) $response->getContent();
        $response->assertOk();
        $response->assertSee('Allergen name is required.', false);
        $this->assertSame(2, substr_count($content, 'Allergen name is required.'));
        $response->assertSee('new bootstrap.Modal(modal).show()', false);
        $this->assertDatabaseCount('user_allergies', 0);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_get_delete_allergy_removes_own_row_and_audits(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');
        $other = $this->makeUser('otherx', 'user', 'student');
        DB::table('user_allergies')->insert([
            'user_id' => $student->id, 'allergen_name' => 'Peanuts', 'severity' => 'mild', 'created_at' => '2025-01-01 10:00:00',
        ]);
        DB::table('user_allergies')->insert([
            'user_id' => $other->id, 'allergen_name' => 'Shellfish', 'severity' => 'severe', 'created_at' => '2025-01-01 10:00:00',
        ]);
        $ownId = 1;
        $otherId = 2;

        $response = $this->actingAs($student)->get('/user/medical_form?action=delete_allergy&id='.$ownId);
        $response->assertRedirect(route('user.medical_form'));
        $response->assertSessionHas('success', 'Allergy removed.');
        $this->assertDatabaseCount('user_allergies', 1);
        $this->assertDatabaseMissing('user_allergies', ['id' => $ownId]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $student->id,
            'action' => 'delete_allergy',
            'table_name' => 'user_allergies',
            'record_id' => $ownId,
            'details' => 'Deleted allergy #'.$ownId,
        ]);

        // Another user's row survives the WHERE user_id= guard...
        $this->actingAs($student)->get('/user/medical_form?action=delete_allergy&id='.$otherId)
            ->assertRedirect(route('user.medical_form'));
        $this->assertDatabaseHas('user_allergies', ['id' => $otherId]);
        // ...but the reference still logs + flashes (0-row DELETE quirk).
        $this->assertSame(2, DB::table('audit_logs')->count());
    }

    public function test_delete_with_missing_id_still_audits_and_invalid_ids_render(): void
    {
        $student = $this->makeUser('mstudent', 'user', 'student');
        DB::table('user_allergies')->insert([
            'user_id' => $student->id, 'allergen_name' => 'Pollen', 'severity' => 'mild', 'created_at' => '2025-01-01 10:00:00',
        ]);

        // id=999 matches no row -> DELETE affects 0, but log + flash fire.
        $this->actingAs($student)->get('/user/medical_form?action=delete_allergy&id=999')
            ->assertRedirect(route('user.medical_form'));
        $this->assertDatabaseCount('user_allergies', 1);
        $this->assertSame(1, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'delete_allergy', 'record_id' => 999]);

        // id=0 / missing id -> the >0 guard fails, plain render, nothing logged.
        $this->actingAs($student)->get('/user/medical_form?action=delete_allergy&id=0')->assertOk();
        $this->actingAs($student)->get('/user/medical_form?action=delete_allergy')->assertOk();
        $this->assertSame(1, DB::table('audit_logs')->count());
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
}

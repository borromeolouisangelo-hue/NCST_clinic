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
 * Phase 12 certificate tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects.
 *
 * Covers staff/certificates.php: the issue handler with its two exact
 * messages and crafted-POST quirks (lines 12-38), the MED numbering
 * (functions.php:720 - callers never pass a type, so excuse slips and fit
 * notes also get MED), the ?action=delete GET handler (40-45), the print
 * fetch (53-69, no ownership check), the search + 200-row cap (71-83) and
 * the dead ?type= filter (the SQL never reads it while the <select>
 * follows the raw parameter), plus user/my_certificates.php (own rows,
 * ?print= ownership in SQL).
 *
 * SUBSTRING + CAST AS UNSIGNED run natively on SQLite 3.39 (verified), so
 * generate_cert_no() executes the identical SQL on both engines.
 */
class CertificateTest extends TestCase
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

        // Live shape (Phase 12 information_schema probe): issued_at defaults
        // to CURRENT_TIMESTAMP like the original INSERT expects.
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
            $table->dateTime('issued_at')->useCurrent();
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

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key', 80);
            $table->text('setting_value')->nullable();
            $table->dateTime('updated_at')->useCurrent();
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
    // Staff list: render, metrics, empty state (original 155-266)
    // ---------------------------------------------------------------------

    public function test_staff_page_renders_toolbar_modal_metrics_and_empty_state(): void
    {
        $staff = $this->makeStaff();

        $response = $this->actingAs($staff)->get('/staff/certificates');

        $response->assertOk()
            ->assertSee('Total Certificates')
            ->assertSee('Issued Today')
            ->assertSee('Dr. Gabutero')
            ->assertSee('Create Certificate')
            ->assertSee('certificatesSearch', false)
            ->assertSee('No medical certificates created yet.')
            ->assertSee('Issue Certificate');

        // No print branch, no errors shown.
        $response->assertDontSee('MEDICAL CERTIFICATE');
        $response->assertDontSee('Select a patient.');
    }

    public function test_list_shows_rows_searches_and_caps_at_200(): void
    {
        $staff = $this->makeStaff();
        $maria = $this->makePatient('Maria', 'Santos', 'student');
        $juan = $this->makePatient('Juan', 'Dela Cruz', 'employee');

        $this->makeCert($maria->id, $staff->id, 'medical', 'Asthma exacerbation');
        $this->makeCert($juan->id, $staff->id, 'fit', 'Return to work clearance');
        $this->makeCert($maria->id, $staff->id, 'excuse', 'Dental appointment');

        // Search matches patient last name and purpose, like the original SQL.
        $this->actingAs($staff)->get('/staff/certificates?q=Santos')->assertSee('Asthma exacerbation');
        $this->actingAs($staff)->get('/staff/certificates?q=clearance')->assertSee('Return to work clearance');

        // List is capped at 200 rows (original LIMIT 200).
        for ($i = 0; $i < 205; $i++) {
            $this->makeCert($maria->id, $staff->id, 'medical', "Bulk $i");
        }
        $body = $this->actingAs($staff)->get('/staff/certificates')->assertOk()->getContent();
        $this->assertSame(200, substr_count($body, '?print='), 'LIMIT 200 must render exactly 200 print links');
    }

    // ---------------------------------------------------------------------
    // Issue (original 12-38)
    // ---------------------------------------------------------------------

    public function test_valid_post_issues_audits_flashes_and_redirects_to_print(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Anna', 'Cruz', 'student');

        $response = $this->actingAs($staff)->post('/staff/certificates', [
            'patient_id' => $patient->id,
            'cert_type' => 'medical',
            'purpose' => 'School clearance',
            'findings' => 'No findings',
            'recommendation' => 'Rest',
            'rest_days' => '3',
        ]);

        $cert = DB::table('medical_certificates')->first();
        $this->assertNotNull($cert);
        $this->assertSame('MED000001', $cert->cert_no);
        $this->assertSame($patient->id, (int) $cert->user_id);
        $this->assertSame((int) $staff->id, (int) $cert->issued_by);
        $this->assertSame('3', (string) $cert->rest_days);
        $this->assertNotNull($cert->issued_at);

        $response->assertRedirect(route('staff.certificates', ['print' => $cert->id]))
            ->assertSessionHas('success', 'Certificate issued.');

        $audit = DB::table('audit_logs')->where('action', 'issue_certificate')->first();
        $this->assertNotNull($audit);
        $this->assertSame('medical_certificates', $audit->table_name);
        $this->assertSame((int) $cert->id, (int) $audit->record_id);
        $this->assertSame("Issued certificate {$cert->cert_no}", $audit->details);

        // The redirect target renders the printable certificate.
        $this->actingAs($staff)->get('/staff/certificates?print='.$cert->id)
            ->assertOk()
            ->assertSee('MEDICAL CERTIFICATE')
            ->assertSee('Anna Cruz')
            ->assertSee('Recommended rest: 3 day(s).')
            ->assertSee('No findings');
    }

    public function test_cert_number_continues_the_max_sequence_and_keeps_med_for_every_type(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Leo', 'Reyes', 'student');

        $this->makeCert($patient->id, $staff->id, 'medical', 'A', ['cert_no' => 'MED000041']);
        $this->makeCert($patient->id, $staff->id, 'medical', 'B', ['cert_no' => 'MED000009']);
        $this->makeCert($patient->id, $staff->id, 'fit', 'C', ['cert_no' => 'FIT000099']);

        // MAX over MED rows only: 41 + 1 -> MED000042 (FIT rows ignored).
        $this->actingAs($staff)->post('/staff/certificates', [
            'patient_id' => $patient->id,
            'cert_type' => 'excuse',
            'purpose' => 'Legacy numbering',
        ])->assertSessionHas('success', 'Certificate issued.');

        $latest = DB::table('medical_certificates')->orderByDesc('id')->first();
        $this->assertSame('MED000042', $latest->cert_no);
        $this->assertSame('excuse', $latest->cert_type);
    }

    public function test_invalid_post_collects_both_errors_in_original_order_without_writing(): void
    {
        $staff = $this->makeStaff();

        $response = $this->actingAs($staff)->post('/staff/certificates', [
            'patient_id' => 0,
            'purpose' => '',
        ]);

        $response->assertOk()
            ->assertSeeInOrder(['Select a patient.', 'Purpose is required.']);

        $this->assertDatabaseCount('medical_certificates', 0);
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_whitespace_purpose_fails_but_blank_rest_and_missing_type_keep_original_semantics(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Nina', 'Lopez', 'student');

        // Whitespace-only purpose trims to '' -> second rule alone.
        $this->actingAs($staff)->post('/staff/certificates', [
            'patient_id' => $patient->id,
            'purpose' => '   ',
        ])->assertOk()->assertSee('Purpose is required.')
            ->assertDontSee('Select a patient.');
        $this->assertDatabaseCount('medical_certificates', 0);

        // Crafted POST: present-but-empty cert_type stays '' (CESN null ->
        // has() is true, same as PHP's ?? only catching null); rest_days
        // 'abc' casts to 0, a blank field casts to null; an omitted
        // cert_type key falls back to 'medical'.
        $this->actingAs($staff)->post('/staff/certificates', [
            'patient_id' => $patient->id,
            'cert_type' => '',
            'purpose' => 'Crafted',
            'rest_days' => 'abc',
        ])->assertSessionHas('success', 'Certificate issued.');
        $crafted = DB::table('medical_certificates')->orderByDesc('id')->first();
        $this->assertSame('', $crafted->cert_type);
        $this->assertSame(0, (int) $crafted->rest_days);

        $this->actingAs($staff)->post('/staff/certificates', [
            'patient_id' => $patient->id,
            'purpose' => 'Blank rest field',
            'rest_days' => '',
        ])->assertSessionHas('success', 'Certificate issued.');
        $blank = DB::table('medical_certificates')->orderByDesc('id')->first();
        $this->assertSame('medical', $blank->cert_type);
        $this->assertNull($blank->rest_days);
    }

    // ---------------------------------------------------------------------
    // Delete (original 40-45): plain GET, no ownership check
    // ---------------------------------------------------------------------

    public function test_delete_via_get_logs_audit_flashes_and_tolerates_missing_rows(): void
    {
        $staff = $this->makeStaff();
        $other = $this->makeStaff2();
        $patient = $this->makePatient('Omar', 'Santos', 'employee');

        // The staff member deletes a certificate issued by someone else -
        // the original has no ownership check on this GET.
        $victim = $this->makeCert($patient->id, $other->id, 'medical', 'Doomed', ['cert_no' => 'MED000007']);

        $this->actingAs($staff)->get('/staff/certificates?action=delete&id='.$victim)
            ->assertRedirect(route('staff.certificates'))
            ->assertSessionHas('success', 'Certificate deleted.');

        $this->assertDatabaseMissing('medical_certificates', ['id' => $victim]);
        $audit = DB::table('audit_logs')->where('action', 'delete_certificate')->first();
        $this->assertNotNull($audit);
        $this->assertSame("Deleted certificate #$victim", $audit->details);

        // Missing / zero ids fall through without touching the data: a
        // non-positive id never enters the handler, so the list simply
        // renders (the original's `&& ($cid = (int)...) > 0` guard).
        $this->actingAs($staff)->get('/staff/certificates?action=delete&id=999')
            ->assertRedirect(route('staff.certificates'));
        $this->actingAs($staff)->get('/staff/certificates?action=delete')
            ->assertOk();
        $this->assertDatabaseCount('medical_certificates', 0);
    }

    // ---------------------------------------------------------------------
    // Print + the dead ?type= filter (original 53-69, 174-177, 244-248)
    // ---------------------------------------------------------------------

    public function test_print_renders_any_certificate_and_a_missing_print_falls_back_to_the_list(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Pia', 'Gonzales', 'student');
        DB::table('student_details')->insert([
            'user_id' => $patient->id, 'student_no' => '2026-0001',
        ]);

        $id = $this->makeCert($patient->id, $staff->id, 'medical', 'Migraine', [
            'purpose' => 'Exam exemption',
            'findings' => 'Migraine observed',
            'rest_days' => 2,
        ]);

        $print = $this->actingAs($staff)->get('/staff/certificates?print='.$id);
        $print->assertOk()
            ->assertSee('MEDICAL CERTIFICATE')
            ->assertSee('Pia Gonzales')
            ->assertSee('Exam exemption')
            ->assertSee('Migraine observed')
            ->assertSee('Recommended rest: 2 day(s).')
            ->assertSee('GERALINE S. GABUTERO, M.D.')
            ->assertDontSee('No medical certificates created yet.');

        // No ownership check on the staff print fetch - and an unknown id
        // renders the LIST branch (with whatever rows exist), not a 404.
        $this->actingAs($staff)->get('/staff/certificates?print=999')
            ->assertOk()
            ->assertSee('Exam exemption')
            ->assertDontSee('MEDICAL CERTIFICATE');
    }

    public function test_dead_type_filter_never_filters_sql_but_the_select_follows_the_raw_parameter(): void
    {
        $staff = $this->makeStaff();
        $patient = $this->makePatient('Ria', 'Tan', 'student');
        $this->makeCert($patient->id, $staff->id, 'medical', 'Medical row');
        $this->makeCert($patient->id, $staff->id, 'fit', 'Fit row');

        // ?type=medical still lists EVERY row (the SQL never reads type).
        $sel = $this->actingAs($staff)->get('/staff/certificates?type=medical');
        $sel->assertOk()->assertSee('Medical row')->assertSee('Fit row');
        $sel->assertSee('<option value="medical" selected>', false);

        // An invalid type selects nothing while the listing stays complete.
        $bad = $this->actingAs($staff)->get('/staff/certificates?type=xyz');
        $bad->assertSee('Medical row')->assertSee('Fit row')
            ->assertSee('<option value="all" >All Types</option>', false);

        // Missing parameter behaves as 'all'.
        $none = $this->actingAs($staff)->get('/staff/certificates');
        $none->assertSee('<option value="all" selected>', false);
    }

    // ---------------------------------------------------------------------
    // user/my_certificates.php
    // ---------------------------------------------------------------------

    public function test_user_page_lists_only_own_rows_and_print_checks_ownership(): void
    {
        $staff = $this->makeStaff();
        $student = $this->makeStudent('Bea', 'Lim');
        $coworker = $this->makeStudent('Tim', 'Ong');
        DB::table('student_details')->insert([
            ['user_id' => $student->id, 'student_no' => '2026-1111'],
            ['user_id' => $coworker->id, 'student_no' => '2026-2222'],
        ]);

        $own = $this->makeCert($student->id, $staff->id, 'medical', 'Own row', [
            'purpose' => 'Field trip', 'rest_days' => 1,
        ]);
        $foreign = $this->makeCert($coworker->id, $staff->id, 'medical', 'Not mine', ['purpose' => 'Sick leave']);

        // Empty list first, like the original for a fresh user.
        $this->actingAs($this->makeStudent('Zed', 'Zoo'))->get('/user/my_certificates')
            ->assertOk()->assertSee('You have no certificates yet.');

        $list = $this->actingAs($student)->get('/user/my_certificates');
        $list->assertOk()
            ->assertSee('Field trip')
            ->assertDontSee('Not mine');

        // Print: own row renders with the Student No. clause; someone
        // else's id falls back to the list branch (SQL ownership check).
        $this->actingAs($student)->get('/user/my_certificates?print='.$own)
            ->assertOk()
            ->assertSee('To Whom It May Concern:')
            ->assertSee('(Student No. 2026-1111)')
            ->assertSee('Recommended rest:')
            ->assertSee('1 day(s)');

        // The list branch (own rows) instead of the foreign print branch.
        $this->actingAs($student)->get('/user/my_certificates?print='.$foreign)
            ->assertOk()
            ->assertSee('Field trip')
            ->assertDontSee('To Whom It May Concern:');

        $this->actingAs($student)->get('/user/my_certificates?print=999')
            ->assertOk()
            ->assertSee('Field trip')
            ->assertDontSee('To Whom It May Concern:');
    }

    // ---------------------------------------------------------------------
    // Authz: require_login(['staff']) / require_login(['user'])
    // ---------------------------------------------------------------------

    public function test_authz_for_both_pages_matches_require_login(): void
    {
        $staff = $this->makeStaff();
        $student = $this->makeStudent('Cara', 'Reyes');
        $admin = $this->makeAdmin();

        $this->get('/staff/certificates')->assertRedirect(route('login'));
        $this->get('/user/my_certificates')->assertRedirect(route('login'));

        $this->actingAs($student)->get('/staff/certificates')->assertRedirect(route('user.dashboard'));
        $this->actingAs($admin)->get('/staff/certificates')->assertRedirect(route('admin.dashboard'));

        $this->actingAs($staff)->get('/user/my_certificates')->assertRedirect(route('staff.dashboard'));
        $this->actingAs($admin)->get('/user/my_certificates')->assertRedirect(route('admin.dashboard'));

        // The staff POST handler is behind the same role guard.
        $this->actingAs($student)->post('/staff/certificates', [
            'patient_id' => 1, 'purpose' => 'x',
        ])->assertRedirect(route('user.dashboard'));
        $this->assertDatabaseCount('medical_certificates', 0);

        $this->actingAs($staff)->get('/staff/certificates')->assertOk();
        $this->actingAs($student)->get('/user/my_certificates')->assertOk();
    }

    // ---------------------------------------------------------------------
    // get_setting: value / default / NULL-row semantics (functions.php:32)
    // ---------------------------------------------------------------------

    public function test_get_setting_returns_value_default_or_null_exactly_like_the_reference(): void
    {
        DB::table('system_settings')->insert([
            ['setting_key' => 'school_name', 'setting_value' => 'NCST'],
            ['setting_key' => 'clinic_contact', 'setting_value' => null],
        ]);

        $this->assertSame('NCST', get_setting('school_name'));
        $this->assertSame('Fallback', get_setting('missing_key', 'Fallback'));
        $this->assertSame('', get_setting('missing_key'));
        // A row that exists with a NULL value returns NULL, not the default.
        $this->assertNull(get_setting('clinic_contact', 'Fallback'));
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

    private function makeStaff2(): User
    {
        $user = User::create([
            'username' => 'staff2',
            'email' => 'staff2@school.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => 'staff',
            'account_type' => 'employee',
            'status' => 'active',
        ]);

        Profile::create(['user_id' => $user->id, 'first_name' => 'Other', 'last_name' => 'Nurse']);

        return $user->fresh();
    }

    private function makeStudent(string $first, string $last): User
    {
        $user = User::create([
            'username' => strtolower($first.$last),
            'email' => strtolower($first.$last).'@school.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => 'user',
            'account_type' => 'student',
            'status' => 'active',
        ]);

        Profile::create(['user_id' => $user->id, 'first_name' => $first, 'last_name' => $last]);

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

    private function makeAdmin(): User
    {
        return User::create([
            'username' => 'admin',
            'email' => 'admin@school.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => 'admin',
            'account_type' => null,
            'status' => 'active',
        ]);
    }

    private function makeCert(int $userId, int $issuedBy, string $type, string $label, array $extra = []): int
    {
        return DB::table('medical_certificates')->insertGetId($extra + [
            'cert_no' => 'MED'.str_pad(DB::table('medical_certificates')->count() + 1, 6, '0', STR_PAD_LEFT),
            'user_id' => $userId,
            'issued_by' => $issuedBy,
            'cert_type' => $type,
            'purpose' => $label,
        ]);
    }
}

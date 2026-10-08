<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use App\Support\ShellContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 33 auth/register.php tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects.
 *
 * Covers the guest-only form (full chrome, default 'student' selected,
 * token present, no action attribute), the signed-in bounce for GET AND
 * POST (original lines 7-9), the DIRECT re-render on validation errors
 * (status 200 - no PRG - messages accumulating in the original's order),
 * the trimmed-$old repopulation (passwords never re-rendered), the
 * employee/student number branch (account_type falls back to 'student'
 * for anything but the literal 'employee'), the single COUNT uniqueness
 * gate (username OR email, skipped once field errors exist), and both
 * success paths creating users -> profiles -> student_details|
 * employee_details plus the 'register' audit row and the PRG flash.
 * The 419 path is browser-asserted; feature POSTs use the suite CSRF
 * bypass. CREATE path runs only here, on SQLite - never on the live DB.
 */
class RegisterTest extends TestCase
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
            $table->string('account_type', 20)->nullable()->default('student');
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
    }

    // ---------------------------------------------------------------------
    // Access + chrome
    // ---------------------------------------------------------------------

    public function test_guest_renders_the_full_form_and_signed_in_visitors_bounce(): void
    {
        $student = $this->makeUser('mstudent', 'user');
        $admin = $this->makeUser('adminx', 'admin');

        $response = $this->get('/auth/register');
        $response->assertOk();
        $response->assertSee('<h3 class="mb-0">Create an Account</h3>', false);
        $response->assertSee('<p>Students & Employees of the school</p>', false);
        foreach ([
            '>Account Type</div>', '>Login Credentials</div>', '>Personal Information</div>',
            '>Emergency Contact</div>', '>Student Details</div>', '>Employee Details</div>',
        ] as $section) {
            $response->assertSee($section, false);
        }

        // Self-posting form: no action attribute, token present, default type student.
        $response->assertSee('<form method="post" class="auth-register-form auth-form">', false);
        $this->assertStringNotContainsString('<form method="post" class="auth-register-form auth-form" action', $response->getContent());
        $response->assertSee('name="_token"', false);
        $response->assertSee('<option value="student" selected>Student</option>', false);
        $response->assertSee('<option value="employee" >Employee</option>', false);
        $response->assertSee('<div id="employeeFields" style="display:none;">', false);

        // Inline script + back link.
        $response->assertSee("document.getElementById('account_type')", false);
        $response->assertSee('href="'.route('login').'"', false);
        $response->assertSee('Back to Login</a>', false);

        // Original lines 7-9: signed-in visitors bounce - GET and POST alike.
        $this->actingAs($student)
            ->get('/auth/register')
            ->assertRedirect(route(ShellContext::dashboardRouteName('user')));
        $this->actingAs($admin)
            ->get('/auth/register')
            ->assertRedirect(route(ShellContext::dashboardRouteName('admin')));
        $this->actingAs($student)
            ->post('/auth/register', [])
            ->assertRedirect(route(ShellContext::dashboardRouteName('user')));
    }

    // ---------------------------------------------------------------------
    // Validation - direct re-render (status 200), order, no writes
    // ---------------------------------------------------------------------

    public function test_empty_post_renders_all_errors_in_order_directly(): void
    {
        $response = $this->post('/auth/register', []);
        $response->assertStatus(200); // original re-renders, no PRG
        $response->assertSee('alert alert-danger', false);

        $html = $response->getContent();
        $expected = [
            'Username is required.',
            'A valid email is required.',
            'First name is required.',
            'Last name is required.',
            'Password must be at least 6 characters.',
            'Student number is required.',
            'Please select a valid province.',
        ];
        $pos = -1;
        foreach ($expected as $msg) {
            $at = strpos($html, $msg);
            $this->assertNotFalse($at, "missing: $msg");
            $this->assertGreaterThan($pos, $at, "out of order: $msg");
            $pos = $at;
        }
        $this->assertSame(7, substr_count($html, 'alert alert-danger'), 'seven messages');
        // '' === '' so the mismatch check never fires on an empty POST.
        $response->assertDontSee('Passwords do not match.', false);

        $this->assertSame(0, DB::table('users')->count(), 'nothing written');
    }

    public function test_employee_type_requires_employee_number_and_trims_old_values(): void
    {
        $response = $this->post('/auth/register', [
            'username' => '  kate  ',
            'email' => 'notanemail',
            'first_name' => 'Kate',
            'last_name' => 'Doe',
            'password' => 'secret123',
            'password_confirm' => 'secret123',
            'account_type' => 'employee',
            'province' => 'Cavite',
            'city_municipality' => 'Bacoor City',
        ]);

        $response->assertStatus(200);
        $response->assertSee('A valid email is required.', false);
        $response->assertSee('Employee number is required.', false);
        $response->assertDontSee('Student number is required.', false);
        $response->assertDontSee('Username is required.', false);
        $response->assertDontSee('Password must be at least 6 characters.', false);
        $this->assertSame(2, substr_count($response->getContent(), 'alert alert-danger'));

        // $old repopulation: trimmed username, employee option selected,
        // passwords NEVER re-rendered (they are absent from the $old map).
        $response->assertSee('value="kate"', false);
        $response->assertSee('<option value="employee" selected>Employee</option>', false);
        $response->assertSee('value="notanemail"', false);

        // account_type collapses anything but the literal 'employee' to
        // 'student' (original line 28) - the student number is demanded.
        $fallback = $this->post('/auth/register', [
            'username' => 'kate',
            'email' => 'kate@school.test',
            'first_name' => 'Kate',
            'last_name' => 'Doe',
            'password' => 'secret123',
            'password_confirm' => 'secret123',
            'account_type' => 'banana',
        ]);
        $fallback->assertStatus(200);
        $fallback->assertSee('Student number is required.', false);
        $fallback->assertDontSee('Employee number is required.', false);
    }

    // ---------------------------------------------------------------------
    // Uniqueness - single COUNT over username OR email
    // ---------------------------------------------------------------------

    public function test_duplicate_username_or_email_is_rejected_without_writing(): void
    {
        $this->makeUser('dupe', 'user'); // dupe@school.test

        $byUsername = $this->post('/auth/register', [
            'username' => 'dupe',
            'email' => 'fresh@school.test',
            'first_name' => 'Dupe',
            'last_name' => 'User',
            'password' => 'secret123',
            'password_confirm' => 'secret123',
            'province' => 'Cavite',
            'city_municipality' => 'Bacoor City',
            'student_no' => '2026-0001',
        ]);
        $byUsername->assertStatus(200);
        $byUsername->assertSee('Username or email is already taken.', false);
        $this->assertSame(1, DB::table('users')->count());

        $byEmail = $this->post('/auth/register', [
            'username' => 'brandnew',
            'email' => 'dupe@school.test',
            'first_name' => 'Dupe',
            'last_name' => 'User',
            'password' => 'secret123',
            'password_confirm' => 'secret123',
            'province' => 'Cavite',
            'city_municipality' => 'Bacoor City',
            'student_no' => '2026-0001',
        ]);
        $byEmail->assertStatus(200);
        $byEmail->assertSee('Username or email is already taken.', false);
        $this->assertSame(1, DB::table('users')->count());

        // Uniqueness is skipped once field errors exist (original line 42).
        $invalid = $this->post('/auth/register', ['username' => 'dupe', 'email' => 'nope']);
        $invalid->assertStatus(200);
        $invalid->assertDontSee('Username or email is already taken.', false);
    }

    // ---------------------------------------------------------------------
    // Success paths - transaction, audit, PRG
    // ---------------------------------------------------------------------

    public function test_student_registration_creates_all_rows_audits_and_prgs(): void
    {
        $response = $this->post('/auth/register', [
            'username' => 'newbie',
            'email' => 'newbie@school.test',
            'account_type' => 'student',
            'first_name' => 'New',
            'middle_name' => 'Mid',
            'last_name' => 'Bee',
            'gender' => 'female',
            'birthdate' => '2004-05-06',
            'lot_block' => '12A',
            'street' => '',
            'barangay' => 'Purok5',
            'postal_code' => '6300',
            'city_municipality' => 'Imus City',
            'province' => 'Cavite',
            'contact_no' => '09171234567',
            'emergency_name' => 'Mom Bee',
            'emergency_contact' => '09170000000',
            'password' => 'secret123',
            'password_confirm' => 'secret123',
            'student_no' => '2026-1234',
            'course' => 'BSIT',
            'year_level' => '3',
            'section' => 'A',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('success', 'Account created successfully. You may now log in.');

        $uid = (int) DB::table('users')->where('username', 'newbie')->value('id');
        $this->assertNotSame(0, $uid);
        $user = DB::table('users')->where('username', 'newbie')->first();
        $this->assertSame('user', $user->role);
        $this->assertSame('active', $user->status);
        $this->assertSame('student', $user->account_type);
        $this->assertTrue(Hash::check('secret123', $user->password_hash), 'password hashed');

        $profile = DB::table('profiles')->where('user_id', $uid)->first();
        $this->assertSame('New', $profile->first_name);
        $this->assertSame('Mid', $profile->middle_name);
        $this->assertSame('Bee', $profile->last_name);
        $this->assertSame('female', $profile->gender);
        $this->assertSame('2004-05-06', $profile->birthdate);
        $this->assertSame(
            format_address(['12A', '', 'Purok5', '6300', 'Imus City', 'Cavite']),
            $profile->address
        );
        $this->assertSame('09171234567', $profile->contact_no);
        $this->assertSame('Mom Bee', $profile->emergency_name);
        $this->assertSame('09170000000', $profile->emergency_contact);

        $detail = DB::table('student_details')->where('user_id', $uid)->first();
        $this->assertSame('2026-1234', $detail->student_no);
        $this->assertSame('BSIT', $detail->course);
        $this->assertSame('3', $detail->year_level);
        $this->assertSame('A', $detail->section);
        $this->assertSame(0, DB::table('employee_details')->count());

        $audit = DB::table('audit_logs')->where('action', 'register')->first();
        $this->assertNotSame(null, $audit);
        $this->assertSame($uid, (int) $audit->user_id);
        $this->assertSame('users', $audit->table_name);
        $this->assertSame($uid, (int) $audit->record_id);
        $this->assertSame('New student registered', $audit->details);
    }

    public function test_employee_registration_handles_invalid_gender_and_empty_birthdate(): void
    {
        $response = $this->post('/auth/register', [
            'username' => 'worker',
            'email' => 'worker@school.test',
            'account_type' => 'employee',
            'first_name' => 'Work',
            'last_name' => 'Ers',
            'gender' => 'martian',   // not male/female/other -> NULL
            'birthdate' => '',       // empty -> NULL
            'password' => 'secret123',
            'password_confirm' => 'secret123',
            'province' => 'Cavite',
            'city_municipality' => 'Bacoor City',
            'employee_no' => 'E-777',
            'department' => 'Registrar',
            'position' => 'Clerk',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('success', 'Account created successfully. You may now log in.');

        $uid = (int) DB::table('users')->where('username', 'worker')->value('id');
        $this->assertSame('employee', DB::table('users')->where('username', 'worker')->value('account_type'));

        $profile = DB::table('profiles')->where('user_id', $uid)->first();
        $this->assertNull($profile->gender, 'invalid gender nulled');
        $this->assertNull($profile->birthdate, 'empty birthdate nulled');
        // Province/City are now required fields, so address will contain them
        $this->assertSame('Bacoor City Cavite', $profile->address);

        $detail = DB::table('employee_details')->where('user_id', $uid)->first();
        $this->assertSame('E-777', $detail->employee_no);
        $this->assertSame('Registrar', $detail->department);
        $this->assertSame('Clerk', $detail->position);
        $this->assertSame(0, DB::table('student_details')->count());

        $audit = DB::table('audit_logs')->where('action', 'register')->first();
        $this->assertSame('New employee registered', $audit->details);
        $this->assertTrue(Hash::check('secret123', DB::table('users')->where('username', 'worker')->value('password_hash')));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

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

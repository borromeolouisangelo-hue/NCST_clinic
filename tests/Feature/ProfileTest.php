<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 19 user/profile.php tests against the isolated in-memory SQLite
 * connection that phpunit.xml selects (DB_CONNECTION=sqlite, DB_DATABASE=:memory:).
 *
 * Covers the identity form open to EVERY role (bare require_login()): the
 * account-type branch (student fields vs the else-branch employee fields),
 * prefilled values from the u.* + profile + detail-row query, the exact
 * two-field validation (messages, order, no write on failure, stored
 * values re-rendered), the trimmed UPDATEs into profiles plus the right
 * detail table, the gender whitelist and empty-birthdate NULL storage,
 * the silent no-op UPDATE for accounts without an employee_details row,
 * the form_action fall-through, the PRG flash and the update_profile
 * audit row. Token-less POST -> 419 is middleware (browser-verified;
 * Feature tests bypass VerifyCsrfToken - documented ACCEPTED).
 */
class ProfileTest extends TestCase
{
    /** Password used by every seeded account. */
    private const PASSWORD = 'secret123';

    /**
     * Pictures Feature 5 writes into public/assets/images/profiles.
     *
     * The app stores real bytes on the web root (this deployment has no
     * public/storage symlink, so a Storage disk would build URLs that 404),
     * so every path this class produces is registered here and unlinked in
     * tearDown - cleanup runs even when an assertion fails.
     *
     * @var array<int, string>
     */
    private array $writtenPhotos = [];

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

        // Audit rows the success path writes (created_at owned by the DB
        // default, exactly like the live table).
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

    protected function tearDown(): void
    {
        // Retry the unlink a few times: a real image written here can be
        // held briefly by Windows real-time scanning, and a leftover file
        // would make the NEXT test's upload collide with a locked target.
        foreach ($this->writtenPhotos as $path) {
            for ($try = 0; $try < 20 && is_file($path); $try++) {
                if (@unlink($path)) {
                    break;
                }
                usleep(50_000);
            }
        }
        $this->writtenPhotos = [];

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Render: every role may open the page, each branch of the detail rows
    // ---------------------------------------------------------------------

    public function test_get_renders_for_every_role_and_guest_bounces_to_login(): void
    {
        [$admin, $staff, $student, $employee] = $this->seedFourRoles();

        $this->get('/user/profile')->assertRedirect(route('login'));
        $this->actingAs($admin)->get('/user/profile')->assertOk();
        $this->actingAs($staff)->get('/user/profile')->assertOk();
        $this->actingAs($student)->get('/user/profile')->assertOk();
        $this->actingAs($employee)->get('/user/profile')->assertOk();
    }

    public function test_student_branch_renders_student_fields_only(): void
    {
        $student = $this->seedStudent();

        $response = $this->actingAs($student)->get('/user/profile');
        $response->assertOk();
        $response->assertSee('<i class="bi bi-person-vcard me-1"></i> Personal Information</div>', false);
        $response->assertSee('Student No.', false);
        $response->assertSee('name="course"', false);
        $response->assertSee('name="year_level"', false);
        $response->assertSee('name="section"', false);
        $response->assertDontSee('Employee No.', false);
        $response->assertDontSee('name="department"', false);
        $response->assertDontSee('name="position"', false);
    }

    public function test_employee_branch_renders_employee_fields_only(): void
    {
        $employee = $this->seedEmployee();

        $response = $this->actingAs($employee)->get('/user/profile');
        $response->assertOk();
        $response->assertSee('Employee No.', false);
        $response->assertSee('name="department"', false);
        $response->assertSee('name="position"', false);
        $response->assertDontSee('Student No.', false);
        $response->assertDontSee('name="course"', false);
    }

    public function test_account_without_account_type_takes_the_else_branch(): void
    {
        // admin/staff carry no account_type - the reference's else branch
        // renders the employee fields for them.
        [$admin] = $this->seedFourRoles();

        $response = $this->actingAs($admin)->get('/user/profile');
        $response->assertOk();
        $response->assertSee('Employee No.', false);
        $response->assertSee('name="department"', false);
        $response->assertDontSee('Student No.', false);
        $response->assertDontSee('name="course"', false);
    }

    public function test_values_prefill_including_gender_selection(): void
    {
        $student = $this->seedStudent([
            'first_name' => 'Maria', 'middle_name' => 'Clara', 'last_name' => 'Santos',
            'gender' => 'female', 'birthdate' => '2004-05-06', 'contact_no' => '09171234567',
            'address' => 'Quezon City', 'emergency_name' => 'Ana Santos', 'emergency_contact' => '09180001111',
        ]);

        $response = $this->actingAs($student)->get('/user/profile');
        $response->assertOk();
        $response->assertSee('value="Maria"', false);
        $response->assertSee('value="Clara"', false);
        $response->assertSee('value="Santos"', false);
        $response->assertSee('value="2004-05-06"', false);
        $response->assertSee('value="09171234567"', false);
        $response->assertSee('value="Quezon City"', false);
        $response->assertSee('value="Ana Santos"', false);
        $response->assertSee('value="09180001111"', false);
        $response->assertSee('<option value="female" selected>Female</option>', false);
        $response->assertSee('<option value="">--</option>', false);
        // The readonly ids keep NO name attribute - never posted (and the
        // student no. input therefore also never changes).
        $response->assertSee('value="2024-0001" readonly>', false);
        $response->assertDontSee('name="student_no"', false);
        $response->assertSee('<input type="hidden" name="form_action" value="profile">', false);
        $response->assertSee('<form method="post" data-swal-save="profile">', false);
    }

    // ---------------------------------------------------------------------
    // Validation: exact messages, order, no write, stored values re-render
    // ---------------------------------------------------------------------

    public function test_both_names_missing_renders_both_errors_in_order_without_writing(): void
    {
        $student = $this->seedStudent(['first_name' => 'Kept', 'last_name' => 'Row']);

        $response = $this->actingAs($student)->post('/user/profile', [
            'form_action' => 'profile',
            'first_name' => '   ',
            'last_name' => '',
        ]);

        $content = $response->getContent();
        $response->assertOk();
        $response->assertSee('First name is required.', false);
        $response->assertSee('Last name is required.', false);
        $this->assertTrue(strpos($content, 'First name is required.') < strpos($content, 'Last name is required.'));
        // Row read BEFORE the POST handling: stored values re-render.
        $response->assertSee('value="Kept"', false);
        $response->assertSee('value="Row"', false);
        $this->assertSame('Kept', DB::table('profiles')->where('user_id', $student->id)->value('first_name'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_missing_last_only_renders_single_error(): void
    {
        $student = $this->seedStudent(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $response = $this->actingAs($student)->post('/user/profile', [
            'form_action' => 'profile',
            'first_name' => 'Ada',
            'last_name' => '',
        ]);

        $response->assertOk();
        $response->assertSee('Last name is required.', false);
        $response->assertDontSee('First name is required.', false);
        $this->assertSame('Lovelace', DB::table('profiles')->where('user_id', $student->id)->value('last_name'));
    }

    public function test_foreign_form_action_falls_through_to_the_plain_render(): void
    {
        $student = $this->seedStudent(['first_name' => 'Untouched']);

        $response = $this->actingAs($student)->post('/user/profile', [
            'form_action' => 'something_else',
            'first_name' => 'Changed',
        ]);

        $response->assertOk();
        $response->assertDontSee('First name is required.', false);
        $response->assertSee('value="Untouched"', false);
        $this->assertSame('Untouched', DB::table('profiles')->where('user_id', $student->id)->value('first_name'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    // ---------------------------------------------------------------------
    // Success: profiles + right detail row, trim, PRG flash, audit
    // ---------------------------------------------------------------------

    public function test_student_success_updates_profile_and_detail_rows_with_trim_and_pr_g(): void
    {
        $student = $this->seedStudent();

        $response = $this->actingAs($student)->post('/user/profile', [
            'form_action' => 'profile',
            'first_name' => '  Renz  ',
            'middle_name' => ' M ',
            'last_name' => ' Cruz ',
            'gender' => 'male',
            'birthdate' => '2003-01-02',
            'address' => '  Manila  ',
            'contact_no' => ' 09170000000 ',
            'emergency_name' => '  Tita Cruz ',
            'emergency_contact' => ' 09180000000 ',
            'course' => '  BSIT  ',
            'year_level' => ' 3 ',
            'section' => ' A ',
        ]);

        $response->assertRedirect(route('user.profile'));
        $response->assertSessionHas('success', 'Profile updated.');

        $profile = DB::table('profiles')->where('user_id', $student->id)->first();
        $this->assertSame('Renz', $profile->first_name);
        $this->assertSame('M', $profile->middle_name);
        $this->assertSame('Cruz', $profile->last_name);
        $this->assertSame('male', $profile->gender);
        $this->assertSame('2003-01-02', $profile->birthdate);
        $this->assertSame('Manila', $profile->address);
        $this->assertSame('09170000000', $profile->contact_no);
        $this->assertSame('Tita Cruz', $profile->emergency_name);
        $this->assertSame('09180000000', $profile->emergency_contact);

        $detail = DB::table('student_details')->where('user_id', $student->id)->first();
        $this->assertSame('BSIT', $detail->course);
        $this->assertSame('3', $detail->year_level);
        $this->assertSame('A', $detail->section);
        // The readonly student no. is never touched (no name attribute).
        $this->assertSame('2024-0001', $detail->student_no);

        $this->assertSame(1, DB::table('audit_logs')->count());
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $student->id,
            'action' => 'update_profile',
            'table_name' => 'profiles',
            'record_id' => $student->id,
            'details' => 'Updated own profile',
        ]);
    }

    public function test_employee_success_updates_profile_and_employee_details(): void
    {
        $employee = $this->seedEmployee();

        $response = $this->actingAs($employee)->post('/user/profile', [
            'form_action' => 'profile',
            'first_name' => 'Bea',
            'last_name' => 'Gonzales',
            'gender' => 'female',
            'department' => ' Guidance ',
            'position' => ' Counselor ',
        ]);

        $response->assertRedirect(route('user.profile'));
        $this->assertSame('Gonzales', DB::table('profiles')->where('user_id', $employee->id)->value('last_name'));

        $detail = DB::table('employee_details')->where('user_id', $employee->id)->first();
        $this->assertSame('Guidance', $detail->department);
        $this->assertSame('Counselor', $detail->position);
        // Student fields never written on this branch.
        $this->assertSame(0, DB::table('student_details')->count());
        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    public function test_non_student_without_detail_row_is_a_silent_no_op(): void
    {
        // admin: no employee_details row - the UPDATE matches nothing and
        // no row appears, exactly like the reference.
        [$admin] = $this->seedFourRoles();

        $response = $this->actingAs($admin)->post('/user/profile', [
            'form_action' => 'profile',
            'first_name' => 'Root',
            'last_name' => 'Admin',
            'department' => 'Clinic',
            'position' => 'Head',
        ]);

        $response->assertRedirect(route('user.profile'));
        $this->assertSame('Root', DB::table('profiles')->where('user_id', $admin->id)->value('first_name'));
        $this->assertSame(0, DB::table('employee_details')->where('user_id', $admin->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    public function test_gender_whitelist_and_empty_birthdate_store_null(): void
    {
        $employee = $this->seedEmployee(['gender' => 'male', 'birthdate' => '1990-01-01']);

        $this->actingAs($employee)->post('/user/profile', [
            'form_action' => 'profile',
            'first_name' => 'Kim',
            'last_name' => 'Reyes',
            'gender' => 'not-a-gender',
            'birthdate' => '',
        ])->assertRedirect(route('user.profile'));

        $profile = DB::table('profiles')->where('user_id', $employee->id)->first();
        $this->assertNull($profile->gender);
        $this->assertNull($profile->birthdate);
    }

    public function test_gender_other_is_kept_verbatim(): void
    {
        $employee = $this->seedEmployee();

        $this->actingAs($employee)->post('/user/profile', [
            'form_action' => 'profile',
            'first_name' => 'Kim',
            'last_name' => 'Reyes',
            'gender' => 'other',
        ])->assertRedirect(route('user.profile'));

        $this->assertSame('other', DB::table('profiles')->where('user_id', $employee->id)->value('gender'));
    }

    // ---------------------------------------------------------------------
    // Feature 5 - Profile + Account Settings merged onto this one route:
    // the Profile | Security nav, the password half, and profile pictures.
    // ---------------------------------------------------------------------

    public function test_every_role_has_one_account_settings_sidebar_entry_aimed_at_the_merged_page(): void
    {
        [$admin, $staff, $student, $employee] = $this->seedFourRoles();

        foreach ([$admin, $staff, $student, $employee] as $user) {
            $body = $this->actingAs($user)->get('/user/profile')->assertOk()->getContent();

            // One entry, on the merged route, plus the dropdown copy of it.
            $this->assertStringContainsString('href="'.url('user/profile').'" class="nav-link', $body);
            $this->assertStringContainsString('<a class="dropdown-item" href="'.url('user/profile').'">', $body);

            // The two old entries are gone: no standalone "Profile" item and
            // no per-role settings link in the shell.
            $this->assertStringNotContainsString('<i class="bi bi-person"></i> Profile', $body);
            $this->assertStringNotContainsString('href="'.url('user/settings').'"', $body);
            $this->assertStringNotContainsString('href="'.url('staff/settings').'"', $body);
        }
    }

    public function test_admin_keeps_the_system_settings_entry_under_its_own_name(): void
    {
        [$admin, $staff] = $this->seedFourRoles();

        $adminBody = $this->actingAs($admin)->get('/user/profile')->assertOk()->getContent();
        $this->assertStringContainsString('<i class="bi bi-sliders"></i> System Settings', $adminBody);
        $this->assertStringContainsString('href="'.url('admin/settings').'" class="nav-link', $adminBody);

        // Only admin has that page; nobody else's shell links to it.
        $staffBody = $this->actingAs($staff)->get('/user/profile')->assertOk()->getContent();
        $this->assertStringNotContainsString('href="'.url('admin/settings').'"', $staffBody);
    }

    public function test_the_security_section_is_behind_its_own_nav_and_profile_stays_the_default(): void
    {
        $student = $this->seedStudent();

        $profile = $this->actingAs($student)->get('/user/profile')->assertOk();
        $profile->assertSee('aria-label="Account settings sections"', false);
        $profile->assertSee('<a class="nav-link active" href="'.url('user/profile').'">', false);
        $profile->assertSee('href="'.url('user/profile?tab=security').'"><i class="bi bi-shield-lock me-1"></i> Security</a>', false);
        $profile->assertSee('Profile Picture', false);
        $profile->assertSee('Personal Information', false);
        $profile->assertDontSee('name="current_password"', false);

        $security = $this->actingAs($student)->get('/user/profile?tab=security')->assertOk();
        $security->assertSee('<a class="nav-link active" href="'.url('user/profile?tab=security').'">', false);
        $security->assertSee('Username', false);
        $security->assertSee('name="current_password"', false);
        $security->assertSee('name="new_password"', false);
        $security->assertSee('name="confirm_password"', false);
        $security->assertSee('Update Password', false);
        $security->assertDontSee('name="first_name"', false);

        // A junk ?tab= still lands on the profile section.
        $this->actingAs($student)->get('/user/profile?tab=nope')->assertOk()->assertSee('name="first_name"', false);
    }

    public function test_password_change_from_the_merged_page_swaps_the_hash_and_returns_to_security(): void
    {
        $student = $this->seedStudent();

        $response = $this->actingAs($student)->post('/user/profile', [
            'form_action' => 'password',
            'current_password' => self::PASSWORD,
            'new_password' => 'newsecret456',
            'confirm_password' => 'newsecret456',
        ]);

        $response->assertRedirect(route('user.profile', ['tab' => 'security']));

        $hash = (string) DB::table('users')->where('id', $student->id)->value('password_hash');
        $this->assertTrue(Hash::check('newsecret456', $hash));
        $this->assertFalse(Hash::check(self::PASSWORD, $hash));

        $this->assertSame(1, DB::table('audit_logs')
            ->where('user_id', $student->id)
            ->where('action', 'change_password')
            ->count());
    }

    public function test_password_errors_re_render_the_security_section_in_the_reference_order(): void
    {
        $student = $this->seedStudent();
        $before = (string) DB::table('users')->where('id', $student->id)->value('password_hash');

        $response = $this->actingAs($student)->post('/user/profile', [
            'form_action' => 'password',
            'current_password' => 'wrong',
            'new_password' => '',
            'confirm_password' => 'different',
        ]);

        $response->assertOk();
        $response->assertSee('Current password is incorrect.', false);
        $response->assertSee('New password must be at least 6 characters.', false);
        $response->assertSee('New passwords do not match.', false);
        $response->assertSee('name="current_password"', false);
        $response->assertDontSee('name="first_name"', false);

        $content = $response->getContent();
        $this->assertTrue(strpos($content, 'Current password is incorrect.')
            < strpos($content, 'New password must be at least 6 characters.'));
        $this->assertTrue(strpos($content, 'New password must be at least 6 characters.')
            < strpos($content, 'New passwords do not match.'));

        // Nothing was written, no audit row, no flash for the toast to read.
        $this->assertSame($before, DB::table('users')->where('id', $student->id)->value('password_hash'));
        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertStringNotContainsString('id="flashNotice"', $content);
    }

    public function test_a_successful_change_flashes_its_toast_only_after_the_redirect(): void
    {
        $student = $this->seedStudent();

        $this->actingAs($student)->post('/user/profile', [
            'form_action' => 'password',
            'current_password' => self::PASSWORD,
            'new_password' => 'newsecret456',
            'confirm_password' => 'newsecret456',
        ])->assertRedirect(route('user.profile', ['tab' => 'security']));

        // The carrier rides the request that FOLLOWED the completed POST -
        // exactly what the browser does next.
        $body = $this->actingAs($student)->get('/user/profile?tab=security')->assertOk()->getContent();
        $this->assertStringContainsString('id="flashNotice"', $body);
        $this->assertStringContainsString('data-flash-text="Password updated successfully."', $body);

        // A later reload of the same page carries no confirmation at all.
        $plain = $this->actingAs($student)->get('/user/profile?tab=security')->getContent();
        $this->assertStringNotContainsString('id="flashNotice"', $plain);
    }

    public function test_a_valid_picture_is_stored_under_its_owner_and_audited(): void
    {
        $student = $this->seedStudent();

        // The stored name is derived from the owner, never from the client -
        // known up front, so it is registered for cleanup BEFORE the upload.
        $expected = $student->id.'.png';
        $path = $this->trackPhoto($expected);

        $response = $this->actingAs($student)->post('/user/profile', [
            'form_action' => 'photo',
            'photo_action' => 'upload',
            // Real PNG bytes (this environment has no GD, so the hand-built
            // png() helper stands in for UploadedFile::fake()->image()).
            'photo' => UploadedFile::fake()->createWithContent('avatar.png', $this->png(64, 64)),
        ]);

        $response->assertRedirect(route('user.profile'));

        $this->assertSame($expected, DB::table('profiles')->where('user_id', $student->id)->value('photo'));
        $this->assertFileExists($path);

        $this->assertSame(1, DB::table('audit_logs')
            ->where('user_id', $student->id)
            ->where('action', 'update_profile_photo')
            ->count());

        // The picture then drives both the card and the topbar dropdown.
        $body = $this->actingAs($student)->get('/user/profile')->getContent();
        $this->assertStringContainsString('src="'.asset('assets/images/profiles/'.$expected).'"', $body);
        $this->assertStringContainsString('account-avatar account-avatar-lg', $body);
        $this->assertStringContainsString('> Remove</button>', $body);
    }

    public function test_a_non_image_picture_is_rejected_without_touching_the_row_or_the_disk(): void
    {
        $student = $this->seedStudent();

        $response = $this->actingAs($student)->post('/user/profile', [
            'form_action' => 'photo',
            'photo_action' => 'upload',
            'photo' => UploadedFile::fake()->create('notes.txt', 4, 'text/plain'),
        ]);

        $response->assertOk();
        $response->assertSee('The picture must be an image file.', false);

        $this->assertNull(DB::table('profiles')->where('user_id', $student->id)->value('photo'));
        $this->assertFileDoesNotExist(public_path('assets/images/profiles/'.$student->id.'.txt'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_an_oversized_picture_reports_the_ceiling_and_keeps_the_old_one(): void
    {
        $student = $this->seedStudent();

        // Seed a real picture on disk plus its column, so "the rejected
        // upload kept it" is a claim about the file too, not just the row.
        $path = $this->trackPhoto($student->id.'.png');
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, 'old-picture');
        DB::table('profiles')->where('user_id', $student->id)->update(['photo' => $student->id.'.png']);

        $response = $this->actingAs($student)->post('/user/profile', [
            'form_action' => 'photo',
            'photo_action' => 'upload',
            // A header-valid PNG padded past the ceiling, so the size rule
            // is the only one that can trip - and its message is the one
            // pinned below.
            'photo' => UploadedFile::fake()->createWithContent('big.png', $this->png(64, 64).str_repeat(' ', 3072 * 1024)),
        ]);

        $response->assertOk();
        $response->assertSee('The picture may not be greater than 2 MB.', false);

        // The previous picture is left in place - a rejected upload deletes
        // nothing.
        $this->assertSame($student->id.'.png', DB::table('profiles')->where('user_id', $student->id)->value('photo'));
        $this->assertFileExists($path);
        $this->assertSame('old-picture', file_get_contents($path));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_removing_the_picture_clears_the_column_deletes_the_file_and_falls_back_to_the_placeholder(): void
    {
        $student = $this->seedStudent();
        $path = $this->trackPhoto($student->id.'.png');

        $this->actingAs($student)->post('/user/profile', [
            'form_action' => 'photo',
            'photo_action' => 'upload',
            // Real PNG bytes (this environment has no GD, so the hand-built
            // png() helper stands in for UploadedFile::fake()->image()).
            'photo' => UploadedFile::fake()->createWithContent('avatar.png', $this->png(64, 64)),
        ])->assertRedirect(route('user.profile'));

        $this->assertFileExists($path);

        $removed = $this->actingAs($student)->post('/user/profile', [
            'form_action' => 'photo',
            'photo_action' => 'remove',
        ]);

        $removed->assertRedirect(route('user.profile'));
        $this->assertNull(DB::table('profiles')->where('user_id', $student->id)->value('photo'));
        $this->assertFileDoesNotExist($path);

        $this->assertSame(1, DB::table('audit_logs')
            ->where('user_id', $student->id)
            ->where('action', 'remove_profile_photo')
            ->count());

        $body = $this->actingAs($student)->get('/user/profile')->getContent();
        $this->assertStringContainsString('account-avatar-empty', $body);
        $this->assertStringNotContainsString('> Remove</button>', $body);
    }

    public function test_a_corrupt_payload_wearing_an_image_name_is_rejected_by_content_inspection(): void
    {
        $student = $this->seedStudent();

        // NAMED like a PNG (the mimes rule reads the server-guessed type),
        // but the bytes are not an image - only the getimagesize() content
        // check can catch this.
        $response = $this->actingAs($student)->post('/user/profile', [
            'form_action' => 'photo',
            'photo_action' => 'upload',
            'photo' => UploadedFile::fake()->createWithContent('avatar.png', 'pretend png bytes'),
        ]);

        $response->assertOk();
        $response->assertSee('The picture must be an image file.', false);

        $this->assertNull(DB::table('profiles')->where('user_id', $student->id)->value('photo'));
        $this->assertFileDoesNotExist(public_path('assets/images/profiles/'.$student->id.'.png'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_a_picture_below_the_minimum_dimension_is_rejected(): void
    {
        $student = $this->seedStudent();

        $response = $this->actingAs($student)->post('/user/profile', [
            'form_action' => 'photo',
            'photo_action' => 'upload',
            'photo' => UploadedFile::fake()->createWithContent('avatar.png', $this->png(32, 32)),
        ]);

        $response->assertOk();
        $response->assertSee('The picture must be at least 64 by 64 pixels.', false);

        $this->assertNull(DB::table('profiles')->where('user_id', $student->id)->value('photo'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_a_picture_beyond_the_maximum_dimension_is_rejected(): void
    {
        $student = $this->seedStudent();

        $response = $this->actingAs($student)->post('/user/profile', [
            'form_action' => 'photo',
            'photo_action' => 'upload',
            // 6001x64: both sides clear the 64 px floor, but the width is
            // past the ceiling - only the upper bound may trip.
            'photo' => UploadedFile::fake()->createWithContent('wide.png', $this->png(6001, 64)),
        ]);

        $response->assertOk();
        $response->assertSee('The picture may not be larger than 6000 by 6000 pixels.', false);

        $this->assertNull(DB::table('profiles')->where('user_id', $student->id)->value('photo'));
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_a_real_jpeg_is_accepted_and_stored_under_the_owner(): void
    {
        $student = $this->seedStudent();

        // Read-only reuse of the JPEG already checked into
        // assets/images/profiles (469x612, ~19 KB): real JPEG bytes the
        // content sniff must accept. The upload writes {id}.jpg (the
        // server-guessed extension); the fixture itself is never written
        // to nor registered for cleanup.
        $jpeg = @file_get_contents(public_path('assets/images/profiles/2.jpg'));

        if ($jpeg === false) {
            $this->markTestSkipped('The checked-in JPEG fixture is missing.');
        }

        $expected = $student->id.'.jpg';
        $path = $this->trackPhoto($expected);

        $response = $this->actingAs($student)->post('/user/profile', [
            'form_action' => 'photo',
            'photo_action' => 'upload',
            'photo' => UploadedFile::fake()->createWithContent('portrait.jpg', $jpeg),
        ]);

        $response->assertRedirect(route('user.profile'));
        $this->assertSame($expected, DB::table('profiles')->where('user_id', $student->id)->value('photo'));
        $this->assertFileExists($path);
    }

    public function test_an_upload_cannot_reach_another_users_picture(): void
    {
        $student = $this->seedStudent();
        $employee = $this->seedEmployee();

        $path = $this->trackPhoto($student->id.'.png');

        $this->actingAs($student)->post('/user/profile', [
            'form_action' => 'photo',
            'photo_action' => 'upload',
            'photo' => UploadedFile::fake()->createWithContent('avatar.png', $this->png(64, 64)),
        ])->assertRedirect(route('user.profile'));

        // The owner's row and file are written; the other account sees no
        // column write and no audit row - $uid is always the auth user.
        $this->assertSame($student->id.'.png', DB::table('profiles')->where('user_id', $student->id)->value('photo'));
        $this->assertFileExists($path);
        $this->assertNull(DB::table('profiles')->where('user_id', $employee->id)->value('photo'));
        $this->assertSame(0, DB::table('audit_logs')->where('user_id', $employee->id)->count());
    }

    public function test_the_picture_card_shows_the_identity_header_and_the_square_crop_interface(): void
    {
        $student = $this->seedStudent();

        // Seed a picture state, so the <img> branches (card + topbar) and
        // their default-avatar onerror fallbacks are on screen.
        $path = $this->trackPhoto($student->id.'.png');
        file_put_contents($path, $this->png(64, 64));
        DB::table('profiles')->where('user_id', $student->id)->update(['photo' => $student->id.'.png']);

        $body = $this->actingAs($student)->get('/user/profile')->assertOk()->getContent();

        // The avatar sits beside the user's name and account information,
        // with a default-avatar fallback for a missing or broken image.
        $this->assertStringContainsString('id="profilePhotoIdentity"', $body);
        $this->assertStringContainsString('<h5 class="mb-1">Old Name</h5>', $body);
        $this->assertStringContainsString('@student1', $body);
        $this->assertStringContainsString('src="'.asset('assets/images/profiles/'.$student->id.'.png').'"', $body);
        $this->assertStringContainsString("onerror=\"this.style.display='none'", $body);

        // The topbar avatar falls back to the same default icon on a
        // failed load.
        $this->assertStringContainsString('onerror="this.outerHTML=', $body);

        // The square crop interface: form, JPG/PNG picker, stage, zoom
        // slider, circular preview and cancel - hidden until a file is
        // chosen.
        $this->assertStringContainsString('id="photoForm"', $body);
        $this->assertStringContainsString('accept="image/jpeg,image/png"', $body);
        $this->assertStringContainsString('id="photoCropper" class="d-none mt-3"', $body);
        $this->assertStringContainsString('id="photoCropStage"', $body);
        $this->assertStringContainsString('id="photoCropZoom"', $body);
        $this->assertStringContainsString('id="photoCropPreview"', $body);
        $this->assertStringContainsString('id="photoCropCancel"', $body);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** [admin, staff, student, employee] with profiles (+ detail rows for the last two). */
    private function seedFourRoles(): array
    {
        $admin = $this->makeUser('adminx', 'admin', null);
        $staff = $this->makeUser('staffx', 'staff', 'employee');
        $student = $this->makeUser('studentx', 'user', 'student');
        $employee = $this->makeUser('employeex', 'user', 'employee');

        DB::table('student_details')->insert(['user_id' => $student->id, 'student_no' => '2024-0001']);
        DB::table('employee_details')->insert(['user_id' => $employee->id, 'employee_no' => 'EMP-01']);

        return [$admin, $staff, $student, $employee];
    }

    private function makeUser(string $username, string $role, ?string $accountType): User
    {
        $user = User::create([
            'username' => $username,
            'email' => $username.'@school.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => $role,
            'account_type' => $accountType,
            'status' => 'active',
        ]);

        Profile::create(['user_id' => $user->id, 'first_name' => 'Old', 'last_name' => 'Name']);

        return $user->fresh();
    }

    private function seedStudent(array $profile = []): User
    {
        $user = $this->makeUser('student1', 'user', 'student');
        if ($profile !== []) {
            DB::table('profiles')->where('user_id', $user->id)->update($profile);
        }
        DB::table('student_details')->insert([
            'user_id' => $user->id,
            'student_no' => '2024-0001',
            'course' => 'BSIT',
            'year_level' => '2',
            'section' => 'B',
        ]);

        return $user->fresh();
    }

    private function seedEmployee(array $profile = []): User
    {
        $user = $this->makeUser('employee1', 'user', 'employee');
        if ($profile !== []) {
            DB::table('profiles')->where('user_id', $user->id)->update($profile);
        }
        DB::table('employee_details')->insert([
            'user_id' => $user->id,
            'employee_no' => 'EMP-01',
            'department' => 'HR',
            'position' => 'Clerk',
        ]);

        return $user->fresh();
    }

    /**
     * Minimal valid PNG of the given size, built without GD.
     *
     * This environment has no GD extension, so UploadedFile::fake()->image()
     * is unavailable; a hand-rolled header plus one zlib-compressed bitmap
     * is enough for getimagesize() and the mimes sniff to see real pixels.
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

    /** Register a Feature 5 picture path for teardown cleanup; returns it. */
    private function trackPhoto(string $filename): string
    {
        $path = public_path('assets/images/profiles/'.$filename);
        $this->writtenPhotos[] = $path;

        return $path;
    }
}

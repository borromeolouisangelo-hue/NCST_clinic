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
 * Phase 32 notifications_preferences.php tests against the isolated
 * in-memory SQLite connection that phpunit.xml selects.
 *
 * Covers bare require_login() (all four roles render, guest -> login), the
 * default render (three display groups, eleven form-switches all ON from
 * the $prefs[$type] ?? true default, raw type keys in the spans, the POST
 * form with data-swal-save + token + Save/Back buttons, sidebar never
 * active on this URL per the reference's exact-basename rule), explicit
 * notification_settings rows driving unchecked/checked states (foreign
 * user's disabled row must NOT leak in), the PRG save (all eleven switches
 * written from isset + === '1', missing field = disabled, flash string),
 * the upsert (updates in place, no duplicate rows on the unique
 * (user_id, notification_type) key, other users untouched) and idempotent
 * re-saves. The 419 path is browser-asserted; feature POSTs use the suite
 * CSRF bypass.
 */
class NotificationsPreferencesTest extends TestCase
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

        // Unique (user_id, notification_type) mirrors the live uq_notif_setting
        // key - required for the upsert on both engines.
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('notification_type', 60);
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('updated_at')->useCurrent();
            $table->unique(['user_id', 'notification_type']);
        });
    }

    // ---------------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------------

    public function test_all_four_roles_render_and_guest_redirects(): void
    {
        $admin = $this->makeUser('adminx', 'admin');
        $staff = $this->makeUser('staffx', 'staff');
        $student = $this->makeUser('mstudent', 'user');
        $employee = $this->makeUser('memployee', 'user');

        $this->get('/notifications_preferences')->assertRedirect(route('login'));
        $this->actingAs($admin)->get('/notifications_preferences')->assertOk();
        $this->actingAs($staff)->get('/notifications_preferences')->assertOk();
        $this->actingAs($student)->get('/notifications_preferences')->assertOk();
        $this->actingAs($employee)->get('/notifications_preferences')->assertOk();
    }

    // ---------------------------------------------------------------------
    // Default render (no rows -> everything enabled)
    // ---------------------------------------------------------------------

    public function test_default_render_shows_groups_eleven_on_switches_and_form_chrome(): void
    {
        $student = $this->makeUser('mstudent', 'user');

        $response = $this->actingAs($student)->get('/notifications_preferences');
        $response->assertOk();

        // Card chrome + intro copy.
        $response->assertSee(
            '<div class="card-header"><i class="bi bi-bell me-1"></i> Notification Preferences</div>',
            false
        );
        $response->assertSee('Choose which notifications you want to receive.', false);

        // Three display groups in reference order.
        $response->assertSee('>Medical Services</h6>', false);
        $response->assertSee('>Sick Leave</h6>', false);
        $response->assertSee('>System (Staff)</h6>', false);

        // The switches show the RAW type keys (original line 70 renders $type).
        $response->assertSee('<span>medicine_dispensed</span>', false);
        $response->assertSee('<span>category_created</span>', false);

        // Eleven switches, all checked (absent rows -> ?? true default).
        $html = $response->getContent();
        $this->assertSame(11, substr_count($html, '<input class="form-check-input" type="checkbox"'), '11 switches');
        $this->assertSame(11, substr_count($html, 'name="pref_'), '11 pref names');
        $this->assertSame(11, substr_count($html, 'checked>'), 'all on by default');

        // POST form: token present (reference prints csrf_token), data-swal-save,
        // Save + Back buttons.
        $response->assertSee('<form method="post" data-swal-save="notification preferences">', false);
        $response->assertSee('name="_token"', false);
        $response->assertSee('<i class="bi bi-save me-1"></i> Save Preferences</button>', false);
        $response->assertSee('href="'.url('notifications').'"', false);
        $response->assertSee('<i class="bi bi-arrow-left me-1"></i> Back to Notifications</a>', false);

        // Exact-basename active rule: on this URL NO sidebar item is active
        // (the reference compares basename === notifications.php only).
        $response->assertDontSee(
            'href="'.url('notifications').'" class="nav-link active">',
            false
        );
    }

    // ---------------------------------------------------------------------
    // Stored rows drive the switches; defaults cover the rest
    // ---------------------------------------------------------------------

    public function test_explicit_rows_toggle_only_their_switches_and_others_default_on(): void
    {
        $student = $this->makeUser('mstudent', 'user');
        $foreign = $this->makeUser('otherx', 'user');

        DB::table('notification_settings')->insert([
            // Own disabled row -> unchecked.
            ['user_id' => $student->id, 'notification_type' => 'medicine_dispensed', 'is_enabled' => 0],
            // Own enabled row -> stays checked.
            ['user_id' => $student->id, 'notification_type' => 'sick_leave', 'is_enabled' => 1],
            // Another user's disabled row must NOT leak into this render.
            ['user_id' => $foreign->id, 'notification_type' => 'category_created', 'is_enabled' => 0],
        ]);

        $html = $this->actingAs($student)->get('/notifications_preferences')->assertOk()->getContent();

        // 11 - 1 disabled = 10 checked.
        $this->assertSame(10, substr_count($html, 'checked>'), 'only the own disabled row is off');

        // The disabled switch has no checked attribute after its id line.
        $this->assertMatchesRegularExpression('/id="pref_medicine_dispensed"\s+>/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="pref_medicine_dispensed"\s+checked>/', $html);
        // Own enabled row and the foreign-influenced default stay checked.
        $this->assertMatchesRegularExpression('/id="pref_sick_leave"\s+checked>/', $html);
        $this->assertMatchesRegularExpression('/id="pref_category_created"\s+checked>/', $html);
    }

    // ---------------------------------------------------------------------
    // POST: save (PRG + flash), all eleven written, upsert semantics
    // ---------------------------------------------------------------------

    public function test_save_prgs_with_flash_and_writes_all_eleven_switches_user_scoped(): void
    {
        $student = $this->makeUser('mstudent', 'user');
        $foreign = $this->makeUser('otherx', 'user');
        // A pre-existing foreign disabled row must survive untouched.
        DB::table('notification_settings')->insert([
            ['user_id' => $foreign->id, 'notification_type' => 'medicine_dispensed', 'is_enabled' => 0],
        ]);

        // Checked checkboxes post '1'; the rest are ABSENT -> disabled
        // (original line 34: isset() && === '1').
        $response = $this->actingAs($student)->post('/notifications_preferences', [
            'pref_medicine_dispensed' => '1',
            'pref_sick_leave' => '1',
            'pref_equipment_overdue' => '0',  // present but not '1' -> disabled
        ]);

        $response->assertRedirect(route('notifications.preferences'));
        $response->assertSessionHas('success', 'Notification preferences saved.');

        // Exactly eleven rows for the caller; foreign row untouched.
        $this->assertSame(11, DB::table('notification_settings')->where('user_id', $student->id)->count());
        $this->assertSame(1, DB::table('notification_settings')->where('user_id', $foreign->id)->count());
        $this->assertSame(
            0,
            (int) DB::table('notification_settings')
                ->where('user_id', $foreign->id)
                ->where('notification_type', 'medicine_dispensed')
                ->value('is_enabled'),
            'foreign row untouched'
        );

        $enabled = DB::table('notification_settings')
            ->where('user_id', $student->id)
            ->pluck('is_enabled', 'notification_type');
        $this->assertSame(1, (int) $enabled['medicine_dispensed'], 'posted 1 -> on');
        $this->assertSame(1, (int) $enabled['sick_leave'], 'posted 1 -> on');
        $this->assertSame(0, (int) $enabled['equipment_overdue'], "posted '0' -> off");
        $this->assertSame(0, (int) $enabled['category_created'], 'absent -> off');
        $this->assertSame(0, (int) $enabled['user_created'], 'absent -> off');
    }

    public function test_resave_updates_in_place_without_duplicating(): void
    {
        $student = $this->makeUser('mstudent', 'user');
        DB::table('notification_settings')->insert([
            ['user_id' => $student->id, 'notification_type' => 'medicine_dispensed', 'is_enabled' => 0],
        ]);

        // Flip the existing row ON; everything else arrives fresh.
        $this->actingAs($student)->post('/notifications_preferences', [
            'pref_medicine_dispensed' => '1',
        ])->assertRedirect(route('notifications.preferences'));

        $this->assertSame(11, DB::table('notification_settings')->where('user_id', $student->id)->count(), 'upsert, no dupes');
        $this->assertSame(
            1,
            (int) DB::table('notification_settings')
                ->where('user_id', $student->id)
                ->where('notification_type', 'medicine_dispensed')
                ->value('is_enabled'),
            'existing row updated in place'
        );

        // Second save: all off (nothing posted) - still eleven rows.
        $this->actingAs($student)
            ->post('/notifications_preferences', [])
            ->assertRedirect(route('notifications.preferences'));
        $this->assertSame(11, DB::table('notification_settings')->where('user_id', $student->id)->count());
        $this->assertSame(
            0,
            (int) DB::table('notification_settings')
                ->where('user_id', $student->id)
                ->where('notification_type', 'medicine_dispensed')
                ->value('is_enabled')
        );
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

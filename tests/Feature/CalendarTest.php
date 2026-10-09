<?php

namespace Tests\Feature;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Full-page Calendar for the three roles - the pages the sidebar's Calendar
 * links always pointed at but which no route ever defined (the 404 root
 * cause).
 *
 * Covers: the three named routes and their uniqueness (no duplicate route),
 * the ['auth', 'role:...'] guard pair (wrong role -> own dashboard, guest ->
 * login), the active sidebar state, one #dashboardCalendar element per page
 * (calendar.js initializes the single instance), the full-mode card that
 * renders even with zero events, and the role/identity scoping of
 * DashboardQueries::calendarEvents() - the same event source the dashboards
 * use, so visibility is identical to each dashboard's calendar card.
 *
 * Only the four tables the shell and the sick-leave scoping assertions touch
 * are created; every other event query inside calendarEvents() is wrapped in
 * safeQuery and yields nothing when its table is absent.
 */
class CalendarTest extends TestCase
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
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('reviewed_at')->nullable();
        });
    }

    public function test_the_three_calendar_routes_exist_once_each_and_are_named(): void
    {
        foreach (['admin.calendar', 'staff.calendar', 'user.calendar'] as $name) {
            $this->assertTrue(Route::has($name), "missing named route {$name}");
        }

        foreach (['admin/calendar', 'staff/calendar', 'user/calendar'] as $uri) {
            $count = collect(Route::getRoutes()->getRoutes())
                ->filter(fn ($r) => $r->uri() === $uri)
                ->count();
            $this->assertSame(1, $count, "expected exactly one route for {$uri}");
        }

        $this->assertSame(url('admin/calendar'), route('admin.calendar'));
        $this->assertSame(url('staff/calendar'), route('staff.calendar'));
        $this->assertSame(url('user/calendar'), route('user.calendar'));
    }

    public function test_each_role_opens_its_own_calendar_page(): void
    {
        $cases = [
            [$this->makeUser('boss', 'admin', 'System', 'Admin', 'employee'), 'admin/calendar'],
            [$this->makeUser('clinic', 'staff', 'Clinic', 'Staff', 'employee'), 'staff/calendar'],
            [$this->makeUser('juan', 'user', 'Juan', 'Dela Cruz', 'student'), 'user/calendar'],
        ];

        foreach ($cases as [$actor, $activePath]) {
            $response = $this->actingAs($actor)->get($activePath);

            $response->assertOk();
            $response->assertSee('Clinic Calendar');
            // Full-page mode: the card renders even with zero events and
            // still shows the event count.
            $response->assertSee('0 events');
            // The layout ships the shared calendar.js that initializes the
            // partial's single element.
            $response->assertSee('assets/js/calendar.js');
            $this->assertSame(1, substr_count($response->getContent(), 'id="dashboardCalendar"'));
            // Sidebar active state follows the current route.
            $response->assertSee(url($activePath).'" class="nav-link active', false);
        }
    }

    public function test_wrong_roles_are_redirected_to_their_own_dashboard(): void
    {
        $admin = $this->makeUser('boss', 'admin', 'System', 'Admin', 'employee');
        $staff = $this->makeUser('clinic', 'staff', 'Clinic', 'Staff', 'employee');
        $user = $this->makeUser('juan', 'user', 'Juan', 'Dela Cruz', 'student');

        $this->actingAs($user)->get('/admin/calendar')->assertRedirect(route('user.dashboard'));
        $this->actingAs($user)->get('/staff/calendar')->assertRedirect(route('user.dashboard'));
        $this->actingAs($staff)->get('/admin/calendar')->assertRedirect(route('staff.dashboard'));
        $this->actingAs($staff)->get('/user/calendar')->assertRedirect(route('staff.dashboard'));
        $this->actingAs($admin)->get('/staff/calendar')->assertRedirect(route('admin.dashboard'));
        $this->actingAs($admin)->get('/user/calendar')->assertRedirect(route('admin.dashboard'));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        foreach (['/admin/calendar', '/staff/calendar', '/user/calendar'] as $uri) {
            $this->get($uri)->assertRedirect(route('login'));
        }
    }

    public function test_events_are_scoped_to_role_and_identity(): void
    {
        $admin = $this->makeUser('boss', 'admin', 'System', 'Admin', 'employee');
        $staff = $this->makeUser('clinic', 'staff', 'Clinic', 'Staff', 'employee');
        $owner = $this->makeUser('alice', 'user', 'Alice', 'Student', 'student');
        $other = $this->makeUser('bobby', 'user', 'Bobby', 'Student', 'student');

        DB::table('sick_leaves')->insert([
            'user_id' => $owner->id,
            'reason' => 'Flu-like fever',
            'start_date' => date('Y-m-d'),
            'end_date' => date('Y-m-d', strtotime('+2 days')),
            'status' => 'pending',
        ]);

        // Admin and staff see every pending leave, titled with its owner.
        $this->actingAs($admin)->get('/admin/calendar')
            ->assertOk()
            ->assertSee('Sick Leave: Alice Student (alice)');
        $this->actingAs($staff)->get('/staff/calendar')
            ->assertOk()
            ->assertSee('Sick Leave: Alice Student (alice)');

        // The owner sees their own leave (status-titled) with its reason ...
        $this->actingAs($owner)->get('/user/calendar')
            ->assertOk()
            ->assertSee('Sick Leave: Pending')
            ->assertSee('Flu-like fever');

        // ... and other patients' leaves never appear on their calendar.
        $this->actingAs($other)->get('/user/calendar')
            ->assertOk()
            ->assertDontSee('Sick Leave:')
            ->assertDontSee('Flu-like fever');
    }

    private function makeUser(string $username, string $role, string $firstName, string $lastName, string $accountType): User
    {
        $user = User::create([
            'username' => $username,
            'email' => $username.'@school.test',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => $role,
            'account_type' => $accountType,
            'status' => 'active',
        ]);

        Profile::create(['user_id' => $user->id, 'first_name' => $firstName, 'last_name' => $lastName]);

        return $user->fresh();
    }
}

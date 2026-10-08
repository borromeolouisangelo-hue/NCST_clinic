<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 10 - user/sick_leave.php compatibility stub.
 *
 * The original page (9 lines) runs require_login(['user']) and then a 302
 * to user/dashboard.php ("The sick leave form is now a modal on the
 * dashboard"), checking no request method along the way. Only the users
 * table is touched: nothing renders, so no shell queries run.
 *
 * The sidebar shows the Sick Leave link for employees only (Navigation.php
 * mirrors the original's $isEmployee branch), but the page itself has no
 * account_type gate - a student hitting the URL is redirected the same way.
 */
class UserSickLeaveRedirectTest extends TestCase
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
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/user/sick_leave')->assertRedirect(route('login'));
    }

    public function test_student_redirects_to_the_user_dashboard(): void
    {
        // require_login(['user']) admits every role=user account; the
        // sidebar link is employee-only but the page itself is not.
        $this->actingAs($this->makeUser('student01', 'student'))
            ->get('/user/sick_leave')
            ->assertRedirect(route('user.dashboard'));
    }

    public function test_employee_redirects_to_the_user_dashboard(): void
    {
        $this->actingAs($this->makeUser('employee01', 'employee'))
            ->get('/user/sick_leave')
            ->assertRedirect(route('user.dashboard'));
    }

    public function test_post_redirects_too_like_the_method_agnostic_original(): void
    {
        // The PHP stub never inspects REQUEST_METHOD, so a POST must land
        // on the dashboard as well instead of a 405.
        $this->actingAs($this->makeUser('employee01', 'employee'))
            ->post('/user/sick_leave')
            ->assertRedirect(route('user.dashboard'));
    }

    public function test_wrong_roles_are_bounced_to_their_own_dashboards(): void
    {
        $staff = $this->makeUser('staff01', 'employee', 'staff');
        $this->actingAs($staff)->get('/user/sick_leave')
            ->assertRedirect(route('staff.dashboard'));

        $admin = $this->makeUser('admin01', 'employee', 'admin');
        $this->actingAs($admin)->get('/user/sick_leave')
            ->assertRedirect(route('admin.dashboard'));
    }

    private function makeUser(string $username, string $accountType, string $role = 'user'): User
    {
        return User::create([
            'username' => $username,
            'email' => $username.'@school.test',
            'password_hash' => Hash::make('secret123'),
            'role' => $role,
            'account_type' => $accountType,
            'status' => 'active',
        ]);
    }
}

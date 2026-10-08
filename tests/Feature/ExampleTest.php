<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * GET / is the port of the original index.php role router:
     * a guest is redirected to the login page.
     */
    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }

    /**
     * index.php: a signed-in visitor is redirected to their role dashboard.
     */
    public function test_authenticated_users_are_redirected_to_their_dashboard(): void
    {
        $user = new User;
        $user->id = 1;
        $user->username = 'admin';
        $user->role = 'admin';

        $response = $this->actingAs($user)->get('/');

        $response->assertRedirect(route('admin.dashboard'));
    }
}

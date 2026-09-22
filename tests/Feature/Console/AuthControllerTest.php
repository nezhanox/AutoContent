<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_protected_console_routes(): void
    {
        $this->post('/console/logout')->assertRedirect('/console/login');
    }

    public function test_login_page_renders(): void
    {
        $this->get('/console/login')
            ->assertInertia(fn (Assert $page) => $page->component('Login'));
    }

    public function test_valid_credentials_log_the_user_in_and_redirect_to_the_console(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret12345')]);

        $this->post('/console/login', [
            'email' => $user->email,
            'password' => 'secret12345',
        ])->assertRedirect('/console');

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret12345')]);

        $this->post('/console/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_authenticated_user_can_log_out(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/console/logout')->assertRedirect('/console/login');

        $this->assertGuest();
    }
}

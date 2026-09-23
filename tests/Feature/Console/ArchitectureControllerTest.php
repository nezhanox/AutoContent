<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ArchitectureControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/console/architecture')->assertRedirect('/console/login');
    }

    public function test_authenticated_user_sees_the_architecture_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/console/architecture')
            ->assertInertia(fn (Assert $page) => $page->component('Architecture'));
    }
}

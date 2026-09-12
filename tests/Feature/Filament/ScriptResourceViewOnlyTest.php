<?php

namespace Tests\Feature\Filament;

use App\Models\Enums\ScriptStatus;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScriptResourceViewOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_create_route_no_longer_exists(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get('/admin/scripts/create');

        $response->assertNotFound();
    }

    public function test_a_script_can_be_viewed(): void
    {
        $this->actingAs(User::factory()->create());

        $script = Script::factory()->create(['status' => ScriptStatus::Completed]);

        $this->get('/admin/scripts')->assertSuccessful();
        $this->get("/admin/scripts/{$script->id}")->assertSuccessful();
    }
}

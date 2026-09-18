<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\SocialAccounts\Pages\CreateSocialAccount;
use App\Models\ContentProject;
use App\Models\Enums\SocialPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SocialAccountFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_social_account_saves_the_encrypted_tokens(): void
    {
        $this->actingAs(User::factory()->create());

        $project = ContentProject::factory()->create();

        Livewire::test(CreateSocialAccount::class)
            ->fillForm([
                'content_project_id' => $project->id,
                'platform' => SocialPlatform::TikTok->value,
                'external_account_id' => 'ext-123',
                'username' => 'my_account',
                'access_token' => 'secret-access-token',
                'refresh_token' => 'secret-refresh-token',
                'status' => 'active',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $account = SocialAccount::sole();
        $this->assertSame('secret-access-token', $account->access_token);
        $this->assertSame('secret-refresh-token', $account->refresh_token);
    }
}

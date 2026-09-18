<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Publications\Pages\CreatePublication;
use App\Filament\Resources\Publications\Pages\EditPublication;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PublicationScheduleFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_with_a_future_scheduled_at_sets_status_scheduled(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create();
        $account = SocialAccount::factory()->create(['content_project_id' => $video->content_project_id]);

        Livewire::test(CreatePublication::class)
            ->fillForm([
                'video_id' => $video->id,
                'social_account_id' => $account->id,
                'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $publication = Publication::sole();
        $this->assertSame(PublicationStatus::Scheduled, $publication->status);
    }

    public function test_creating_without_scheduled_at_sets_status_draft(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create();
        $account = SocialAccount::factory()->create(['content_project_id' => $video->content_project_id]);

        Livewire::test(CreatePublication::class)
            ->fillForm([
                'video_id' => $video->id,
                'social_account_id' => $account->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $publication = Publication::sole();
        $this->assertSame(PublicationStatus::Draft, $publication->status);
    }

    public function test_editing_an_already_published_publication_does_not_reset_its_status(): void
    {
        $this->actingAs(User::factory()->create());

        $publication = Publication::factory()->create([
            'status' => PublicationStatus::Published,
            'scheduled_at' => now()->subDay(),
            'published_at' => now(),
            'external_post_id' => 'ext-1',
        ]);

        Livewire::test(EditPublication::class, ['record' => $publication->getRouteKey()])
            ->fillForm(['caption' => 'Updated caption'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(PublicationStatus::Published, $publication->fresh()->status);
        $this->assertSame('Updated caption', $publication->fresh()->caption);
    }
}

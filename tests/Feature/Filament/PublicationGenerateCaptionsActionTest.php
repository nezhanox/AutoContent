<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Publications\Pages\ListPublications;
use App\Jobs\GenerateCaptionsJob;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class PublicationGenerateCaptionsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_captions_action_dispatches_the_job_when_draft_and_no_caption(): void
    {
        $this->actingAs(User::factory()->create());

        Queue::fake();

        $publication = Publication::factory()->create(['status' => PublicationStatus::Draft, 'caption' => null]);

        Livewire::test(ListPublications::class)
            ->callTableAction('generateCaptions', $publication)
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(GenerateCaptionsJob::class, fn (GenerateCaptionsJob $job): bool => $job->publicationId === $publication->id);
    }

    public function test_generate_captions_action_is_not_visible_once_a_caption_exists(): void
    {
        $this->actingAs(User::factory()->create());

        $publication = Publication::factory()->create(['status' => PublicationStatus::Draft, 'caption' => 'Already there']);

        Livewire::test(ListPublications::class)
            ->assertTableActionHidden('generateCaptions', $publication);
    }

    public function test_generate_captions_action_is_visible_once_scheduled_with_no_caption(): void
    {
        $this->actingAs(User::factory()->create());

        $publication = Publication::factory()->create(['status' => PublicationStatus::Scheduled, 'caption' => null]);

        Livewire::test(ListPublications::class)
            ->assertTableActionVisible('generateCaptions', $publication);
    }

    public function test_generate_captions_action_is_not_visible_once_publishing_or_later(): void
    {
        $this->actingAs(User::factory()->create());

        foreach ([PublicationStatus::Publishing, PublicationStatus::Published, PublicationStatus::Failed] as $status) {
            $publication = Publication::factory()->create(['status' => $status, 'caption' => null]);

            Livewire::test(ListPublications::class)
                ->assertTableActionHidden('generateCaptions', $publication);
        }
    }
}

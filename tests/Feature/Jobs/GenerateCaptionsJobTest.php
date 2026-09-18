<?php

namespace Tests\Feature\Jobs;

use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Jobs\GenerateCaptionsJob;
use App\Models\ContentProject;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Video;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GenerateCaptionsJobTest extends TestCase
{
    use RefreshDatabase;

    private function draftPublication(): Publication
    {
        $project = ContentProject::factory()->create(['settings' => []]);
        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'title' => 'How to cook rice',
            'description' => 'A quick guide',
        ]);
        $account = SocialAccount::factory()->create(['content_project_id' => $project->id]);

        return Publication::factory()->create([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'status' => PublicationStatus::Draft,
            'caption' => null,
        ]);
    }

    public function test_it_generates_a_caption_and_hashtags(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['caption' => 'Rice made easy!', 'hashtags' => ['cooking', 'rice']])
            );
        });

        $publication = $this->draftPublication();

        app()->call([new GenerateCaptionsJob($publication->id), 'handle']);

        $fresh = $publication->fresh();
        $this->assertSame('Rice made easy!', $fresh->caption);
        $this->assertSame(['cooking', 'rice'], $fresh->hashtags);
    }

    public function test_it_is_a_no_op_when_caption_is_already_set(): void
    {
        $publication = $this->draftPublication();
        $publication->update(['caption' => 'Already there']);

        app()->call([new GenerateCaptionsJob($publication->id), 'handle']);

        $this->assertSame('Already there', $publication->fresh()->caption);
    }

    public function test_it_is_a_no_op_when_the_publication_is_publishing_or_later(): void
    {
        $publication = $this->draftPublication();
        $publication->update(['status' => PublicationStatus::Publishing]);

        app()->call([new GenerateCaptionsJob($publication->id), 'handle']);

        $this->assertNull($publication->fresh()->caption);
    }

    public function test_it_generates_a_caption_for_a_scheduled_publication(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['caption' => 'Rice made easy!', 'hashtags' => ['cooking', 'rice']])
            );
        });

        $publication = $this->draftPublication();
        $publication->update(['status' => PublicationStatus::Scheduled]);

        app()->call([new GenerateCaptionsJob($publication->id), 'handle']);

        $fresh = $publication->fresh();
        $this->assertSame('Rice made easy!', $fresh->caption);
        $this->assertSame(['cooking', 'rice'], $fresh->hashtags);
    }

    public function test_failed_notifies_admins_and_records_the_error_message(): void
    {
        Notification::fake();
        User::factory()->create();

        $publication = $this->draftPublication();

        $job = new GenerateCaptionsJob($publication->id);
        $job->failed(new \RuntimeException('LLM unavailable'));

        $this->assertStringContainsString('LLM unavailable', $publication->fresh()->error_message);

        Notification::assertSentTo(
            User::all(),
            PipelineJobFailedNotification::class,
            fn (PipelineJobFailedNotification $notification): bool => $notification->context['publication_id'] === $publication->id
        );
    }
}

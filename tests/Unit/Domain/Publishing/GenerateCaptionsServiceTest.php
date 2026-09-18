<?php

namespace Tests\Unit\Domain\Publishing;

use App\Domain\Llm\LlmManagerInterface;
use App\Domain\Llm\Providers\FakeLlmProvider;
use App\Domain\Publishing\Exceptions\CaptionGenerationFailedException;
use App\Domain\Publishing\Services\GenerateCaptionsService;
use App\Models\ContentProject;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateCaptionsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function publicationWithVideo(): Publication
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
        ]);
    }

    public function test_it_parses_a_valid_response(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith(
                json_encode(['caption' => 'Rice made easy!', 'hashtags' => ['cooking', 'rice']])
            );
        });

        $publication = $this->publicationWithVideo()->load('video.contentProject', 'socialAccount');
        $target = app(LlmManagerInterface::class)->resolve($publication->video->contentProject, 'captions');

        $service = app(GenerateCaptionsService::class);
        $result = $service->generate($publication, $target);

        $this->assertSame('Rice made easy!', $result['caption']);
        $this->assertSame(['cooking', 'rice'], $result['hashtags']);
    }

    public function test_it_throws_after_exhausting_repair_attempts_on_invalid_json(): void
    {
        config()->set('llm.default_provider', 'fake');
        config()->set('llm.default_model', 'fake-model');

        $this->app->bind(FakeLlmProvider::class, function () {
            return (new FakeLlmProvider)->respondWith('not json');
        });

        $publication = $this->publicationWithVideo()->load('video.contentProject', 'socialAccount');
        $target = app(LlmManagerInterface::class)->resolve($publication->video->contentProject, 'captions');

        $service = app(GenerateCaptionsService::class);

        $this->expectException(CaptionGenerationFailedException::class);
        $service->generate($publication, $target);
    }
}

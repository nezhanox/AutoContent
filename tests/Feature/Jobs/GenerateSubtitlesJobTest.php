<?php

namespace Tests\Feature\Jobs;

use App\Domain\Video\Providers\FakeTranscriptionProvider;
use App\Domain\Video\TranscriptionProviderInterface;
use App\Jobs\GenerateSubtitlesJob;
use App\Models\ContentProject;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoStatus;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Video;
use App\Models\Voiceover;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateSubtitlesJobTest extends TestCase
{
    use RefreshDatabase;

    private function videoReadyForSubtitles(): Video
    {
        $project = ContentProject::factory()->create(['language' => 'en']);
        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'status' => VideoStatus::AssetsReady,
        ]);
        Voiceover::factory()->create([
            'video_id' => $video->id,
            'file_path' => "projects/{$project->id}/audio/{$video->id}.mp3",
        ]);

        Storage::disk(config('filesystems.default'))->put(
            "projects/{$project->id}/audio/{$video->id}.mp3",
            'fake-audio-bytes'
        );

        return $video;
    }

    private function bindFakeTranscription(array $segments = [], string $language = 'en'): void
    {
        $this->app->bind(TranscriptionProviderInterface::class, function () use ($segments, $language) {
            return (new FakeTranscriptionProvider)->respondWith($segments, $language);
        });
    }

    public function test_it_creates_a_subtitle_media_asset_and_links_it_to_the_video(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->bindFakeTranscription([['start' => 0.0, 'end' => 1.0, 'text' => 'Hi']], 'en');

        $video = $this->videoReadyForSubtitles();

        app()->call([new GenerateSubtitlesJob($video->id), 'handle']);

        $subtitle = MediaAsset::where('type', MediaAssetType::Subtitle)->sole();
        $this->assertSame('whisper', $subtitle->provider);
        $this->assertSame('application/x-subrip', $subtitle->mime_type);
        $this->assertEquals([['start' => 0.0, 'end' => 1.0, 'text' => 'Hi']], $subtitle->metadata['segments']);
        $this->assertSame('en', $subtitle->metadata['language']);

        $expectedPath = "projects/{$video->content_project_id}/subtitles/{$video->id}.srt";
        $this->assertSame($expectedPath, $subtitle->path);
        Storage::disk(config('filesystems.default'))->assertExists($expectedPath);

        $this->assertSame($subtitle->id, $video->fresh()->subtitle_id);
    }

    public function test_it_is_a_no_op_when_the_video_status_is_not_assets_ready(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->bindFakeTranscription();

        $project = ContentProject::factory()->create(['language' => 'en']);
        $video = Video::factory()->create(['content_project_id' => $project->id, 'status' => VideoStatus::VoiceGenerated]);

        app()->call([new GenerateSubtitlesJob($video->id), 'handle']);

        $this->assertDatabaseCount('media_assets', 0);
        $this->assertNull($video->fresh()->subtitle_id);
    }

    public function test_it_is_a_no_op_when_a_subtitle_already_exists(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->bindFakeTranscription();

        $video = $this->videoReadyForSubtitles();
        $existing = MediaAsset::factory()->create(['type' => MediaAssetType::Subtitle]);
        $video->update(['subtitle_id' => $existing->id]);

        app()->call([new GenerateSubtitlesJob($video->id), 'handle']);

        $this->assertSame(1, MediaAsset::where('type', MediaAssetType::Subtitle)->count());
        $this->assertSame($existing->id, $video->fresh()->subtitle_id);
    }

    public function test_calling_handle_twice_does_not_create_a_duplicate_subtitle(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->bindFakeTranscription([['start' => 0.0, 'end' => 1.0, 'text' => 'Hi']]);

        $video = $this->videoReadyForSubtitles();

        app()->call([new GenerateSubtitlesJob($video->id), 'handle']);
        app()->call([new GenerateSubtitlesJob($video->id), 'handle']);

        $this->assertSame(1, MediaAsset::where('type', MediaAssetType::Subtitle)->count());
    }

    public function test_failed_marks_the_video_failed_and_sends_a_notification(): void
    {
        Notification::fake();
        User::factory()->create();

        $video = Video::factory()->create(['status' => VideoStatus::AssetsReady]);

        $job = new GenerateSubtitlesJob($video->id);
        $job->failed(new \RuntimeException('boom'));

        $this->assertSame(VideoStatus::Failed, $video->fresh()->status);

        Notification::assertSentTo(
            User::all(),
            PipelineJobFailedNotification::class,
            fn (PipelineJobFailedNotification $notification): bool => $notification->context['video_id'] === $video->id
        );
    }
}

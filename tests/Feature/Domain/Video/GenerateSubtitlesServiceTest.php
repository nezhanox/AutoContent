<?php

namespace Tests\Feature\Domain\Video;

use App\Domain\Video\Providers\FakeTranscriptionProvider;
use App\Domain\Video\Services\GenerateSubtitlesService;
use App\Domain\Video\TranscriptionProviderInterface;
use App\Domain\Video\TranscriptionResult;
use App\Models\ContentProject;
use App\Models\Video;
use App\Models\Voiceover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class GenerateSubtitlesServiceTest extends TestCase
{
    use RefreshDatabase;

    private function videoWithVoiceover(string $language = 'en'): Video
    {
        $project = ContentProject::factory()->create(['language' => $language]);
        $video = Video::factory()->create(['content_project_id' => $project->id]);
        Voiceover::factory()->create(['video_id' => $video->id, 'file_path' => "projects/{$project->id}/audio/{$video->id}.mp3"]);

        Storage::disk(config('filesystems.default'))->put(
            "projects/{$project->id}/audio/{$video->id}.mp3",
            'fake-audio-bytes'
        );

        return $video->fresh(['voiceover', 'contentProject']);
    }

    public function test_it_returns_segments_language_and_formatted_srt(): void
    {
        Storage::fake(config('filesystems.default'));
        $video = $this->videoWithVoiceover('en');

        $provider = (new FakeTranscriptionProvider)->respondWith(
            [['start' => 0.0, 'end' => 1.0, 'text' => 'Hi']],
            'en'
        );

        $service = new GenerateSubtitlesService($provider);
        $result = $service->generate($video);

        $this->assertSame([['start' => 0.0, 'end' => 1.0, 'text' => 'Hi']], $result['segments']);
        $this->assertSame('en', $result['language']);
        $this->assertStringContainsString('Hi', $result['srt']);
    }

    public function test_it_passes_the_content_projects_language_to_the_provider(): void
    {
        Storage::fake(config('filesystems.default'));
        $video = $this->videoWithVoiceover('uk');

        $provider = new FakeTranscriptionProvider;
        $service = new GenerateSubtitlesService($provider);
        $service->generate($video);

        $this->assertSame('uk', $provider->lastLanguageArgument);
    }

    public function test_it_deletes_the_temporary_audio_file_after_a_successful_transcription(): void
    {
        Storage::fake(config('filesystems.default'));
        $video = $this->videoWithVoiceover();

        $provider = new FakeTranscriptionProvider;
        $service = new GenerateSubtitlesService($provider);
        $service->generate($video);

        $this->assertNotNull($provider->lastAudioPath);
        $this->assertFileDoesNotExist($provider->lastAudioPath);
    }

    public function test_it_deletes_the_temporary_audio_file_even_when_the_provider_throws(): void
    {
        Storage::fake(config('filesystems.default'));
        $video = $this->videoWithVoiceover();

        $throwingProvider = new class implements TranscriptionProviderInterface
        {
            public ?string $capturedPath = null;

            public function transcribe(string $audioPath, ?string $language = null): TranscriptionResult
            {
                $this->capturedPath = $audioPath;

                throw new RuntimeException('boom');
            }
        };

        $service = new GenerateSubtitlesService($throwingProvider);

        try {
            $service->generate($video);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertNotNull($throwingProvider->capturedPath);
        $this->assertFileDoesNotExist($throwingProvider->capturedPath);
    }
}

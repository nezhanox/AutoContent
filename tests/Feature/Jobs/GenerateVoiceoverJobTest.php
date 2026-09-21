<?php

namespace Tests\Feature\Jobs;

use App\Domain\Video\AudioProbeInterface;
use App\Domain\Video\Providers\FakeAudioProbe;
use App\Domain\Video\Providers\FakeTtsProvider;
use App\Domain\Video\TtsProviderInterface;
use App\Domain\Video\VoiceResult;
use App\Domain\Video\VoiceSettings;
use App\Jobs\GenerateVoiceoverJob;
use App\Models\ContentIdea;
use App\Models\ContentProject;
use App\Models\Enums\VideoStatus;
use App\Models\Enums\VoiceoverStatus;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoScene;
use App\Models\Voiceover;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateVoiceoverJobTest extends TestCase
{
    use RefreshDatabase;

    private function videoWithScenesReadyForVoiceover(): Video
    {
        $project = ContentProject::factory()->create(['settings' => ['tts' => ['voice' => 'adam']]]);
        $idea = ContentIdea::factory()->create(['content_project_id' => $project->id]);

        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'content_idea_id' => $idea->id,
            'status' => VideoStatus::ScriptGenerated,
        ]);

        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 0, 'text' => 'Hello']);
        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 1, 'text' => 'world']);

        return $video;
    }

    private function bindFakeAudioProbe(float $duration): void
    {
        $this->app->bind(AudioProbeInterface::class, function () use ($duration) {
            return (new FakeAudioProbe)->respondWith($duration);
        });
    }

    private function bindFakeTts(string $audio = 'audio-bytes'): void
    {
        // Real audio-duration probing shells out to ffprobe, which can't parse
        // this fake, non-audio content, so every fake-TTS test needs a fake
        // probe too (bindFakeAudioProbe() can override the fixed duration after).
        $this->bindFakeAudioProbe(10.0);

        $this->app->bind(TtsProviderInterface::class, function () use ($audio) {
            return (new FakeTtsProvider)->respondWith($audio, 'elevenlabs', ['model_id' => 'eleven_multilingual_v2']);
        });
    }

    public function test_it_creates_a_voiceover_and_writes_the_audio_file(): void
    {
        Storage::fake('local');
        $this->bindFakeTts();

        $video = $this->videoWithScenesReadyForVoiceover();

        app()->call([new GenerateVoiceoverJob($video->id), 'handle']);

        $voiceover = Voiceover::where('video_id', $video->id)->sole();
        $this->assertSame('elevenlabs', $voiceover->provider);
        $this->assertSame('adam', $voiceover->voice);
        $this->assertSame('Hello world', $voiceover->text);
        $this->assertSame(VoiceoverStatus::Completed, $voiceover->status);
        $this->assertSame("projects/{$video->content_project_id}/audio/{$video->id}.mp3", $voiceover->file_path);

        Storage::disk('local')->assertExists($voiceover->file_path);
        $this->assertSame('audio-bytes', Storage::disk('local')->get($voiceover->file_path));

        $this->assertSame(VideoStatus::VoiceGenerated, $video->fresh()->status);
    }

    public function test_it_rescales_scene_durations_to_match_the_actual_voiceover_duration(): void
    {
        // Regression test: scene durations are the script-writing LLM's guess at
        // pacing, which can drift far from the TTS engine's actual speaking rate.
        // FfmpegVideoRenderer trims the final render to the sum of scene durations,
        // so without this rescale the end of the narration gets silently cut off.
        Storage::fake('local');
        $this->bindFakeTts();
        $this->bindFakeAudioProbe(12.0);

        $video = $this->videoWithScenesReadyForVoiceover();
        $scenes = VideoScene::where('video_id', $video->id)->orderBy('order')->get();
        $scenes[0]->update(['duration' => 2]);
        $scenes[1]->update(['duration' => 2]);

        app()->call([new GenerateVoiceoverJob($video->id), 'handle']);

        $voiceover = Voiceover::where('video_id', $video->id)->sole();
        $this->assertSame(12, $voiceover->duration);

        $rescaled = VideoScene::where('video_id', $video->id)->orderBy('order')->get();
        $this->assertSame(6, $rescaled[0]->duration);
        $this->assertSame(6, $rescaled[1]->duration);
        $this->assertSame(12, $rescaled->sum('duration'));
    }

    public function test_it_creates_no_voiceover_and_does_not_change_video_status_when_the_tts_call_fails(): void
    {
        Storage::fake('local');

        $this->app->bind(TtsProviderInterface::class, function () {
            return new class implements TtsProviderInterface
            {
                public function generate(string $text, VoiceSettings $settings): VoiceResult
                {
                    throw new \RuntimeException('ElevenLabs request failed: simulated failure');
                }
            };
        });

        $video = $this->videoWithScenesReadyForVoiceover();

        $this->expectException(\RuntimeException::class);

        try {
            app()->call([new GenerateVoiceoverJob($video->id), 'handle']);
        } finally {
            $this->assertDatabaseCount('voiceovers', 0);
            $this->assertSame(VideoStatus::ScriptGenerated, $video->fresh()->status);
        }
    }

    public function test_it_is_a_no_op_when_the_video_status_is_not_script_generated(): void
    {
        Storage::fake('local');
        $this->bindFakeTts();

        $project = ContentProject::factory()->create(['settings' => ['tts' => ['voice' => 'adam']]]);
        $video = Video::factory()->create(['content_project_id' => $project->id, 'status' => VideoStatus::Draft]);

        app()->call([new GenerateVoiceoverJob($video->id), 'handle']);

        $this->assertDatabaseCount('voiceovers', 0);
    }

    public function test_it_is_a_no_op_when_a_voiceover_already_exists(): void
    {
        Storage::fake('local');
        $this->bindFakeTts();

        $video = $this->videoWithScenesReadyForVoiceover();
        Voiceover::factory()->create(['video_id' => $video->id, 'status' => 'completed']);

        app()->call([new GenerateVoiceoverJob($video->id), 'handle']);

        $this->assertSame(1, Voiceover::where('video_id', $video->id)->count());
    }

    public function test_calling_handle_twice_does_not_create_a_duplicate_voiceover(): void
    {
        Storage::fake('local');
        $this->bindFakeTts();

        $video = $this->videoWithScenesReadyForVoiceover();

        app()->call([new GenerateVoiceoverJob($video->id), 'handle']);
        app()->call([new GenerateVoiceoverJob($video->id), 'handle']);

        $this->assertSame(1, Voiceover::where('video_id', $video->id)->count());
    }

    public function test_the_unique_index_prevents_a_second_voiceover_for_the_same_video_at_the_database_level(): void
    {
        $video = $this->videoWithScenesReadyForVoiceover();

        Voiceover::factory()->create(['video_id' => $video->id]);

        $this->expectException(UniqueConstraintViolationException::class);

        Voiceover::factory()->create(['video_id' => $video->id]);
    }

    public function test_failed_marks_the_video_failed_and_sends_a_notification(): void
    {
        Notification::fake();
        User::factory()->create();

        $video = Video::factory()->create(['status' => VideoStatus::ScriptGenerated]);

        $job = new GenerateVoiceoverJob($video->id);
        $job->failed(new \RuntimeException('boom'));

        $this->assertSame(VideoStatus::Failed, $video->fresh()->status);

        Notification::assertSentTo(
            User::all(),
            PipelineJobFailedNotification::class,
            fn (PipelineJobFailedNotification $notification): bool => $notification->context['video_id'] === $video->id
        );
    }
}

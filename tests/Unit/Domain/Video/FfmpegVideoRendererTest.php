<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\Providers\FfmpegVideoRenderer;
use App\Models\ContentProject;
use App\Models\Enums\MediaAssetType;
use App\Models\MediaAsset;
use App\Models\Video;
use App\Models\VideoScene;
use App\Models\Voiceover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class FfmpegVideoRendererTest extends TestCase
{
    use RefreshDatabase;

    private function fakeFfmpegProcesses(): void
    {
        Process::fake(function ($process) {
            $command = $process->command;

            if (in_array('-show_streams', $command, true)) {
                return Process::result(output: json_encode([
                    'format' => ['duration' => '9.50'],
                    'streams' => [
                        ['codec_type' => 'video', 'width' => 1080, 'height' => 1920],
                        ['codec_type' => 'audio'],
                    ],
                ]));
            }

            $output = end($command);
            if (is_string($output) && str_ends_with($output, '.mp4')) {
                file_put_contents($output, 'fake-video-bytes');
            }

            return Process::result(output: '');
        });
    }

    private function buildVideo(bool $withMusic = false): Video
    {
        $project = ContentProject::factory()->create();
        $video = Video::factory()->create(['content_project_id' => $project->id]);

        $sceneAsset1 = MediaAsset::factory()->create(['type' => MediaAssetType::Image, 'path' => 'assets/scene1.jpg']);
        $sceneAsset2 = MediaAsset::factory()->create(['type' => MediaAssetType::Video, 'path' => 'assets/scene2.mp4']);

        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 0, 'duration' => 3, 'asset_id' => $sceneAsset1->id]);
        VideoScene::factory()->create(['video_id' => $video->id, 'order' => 1, 'duration' => 4, 'asset_id' => $sceneAsset2->id]);

        Voiceover::factory()->create([
            'video_id' => $video->id,
            'file_path' => "projects/{$project->id}/audio/{$video->id}.mp3",
        ]);

        $subtitle = MediaAsset::factory()->create([
            'type' => MediaAssetType::Subtitle,
            'metadata' => ['segments' => [['start' => 0.0, 'end' => 1.0, 'text' => 'Hi']], 'language' => 'en'],
        ]);
        $video->update(['subtitle_id' => $subtitle->id]);

        if ($withMusic) {
            $music = MediaAsset::factory()->create(['type' => MediaAssetType::Audio, 'path' => 'assets/music.mp3']);
            $video->update(['music_asset_id' => $music->id]);
            Storage::disk(config('filesystems.default'))->put('assets/music.mp3', 'fake-music-bytes');
        }

        Storage::disk(config('filesystems.default'))->put('assets/scene1.jpg', 'fake-image-bytes');
        Storage::disk(config('filesystems.default'))->put('assets/scene2.mp4', 'fake-video-bytes');
        Storage::disk(config('filesystems.default'))->put("projects/{$project->id}/audio/{$video->id}.mp3", 'fake-audio-bytes');

        return $video->fresh(['scenes.asset', 'voiceover', 'subtitle', 'musicAsset']);
    }

    public function test_it_renders_and_returns_probed_dimensions_and_duration(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeFfmpegProcesses();

        $video = $this->buildVideo();
        $renderer = new FfmpegVideoRenderer;

        $result = $renderer->render($video);

        $this->assertSame(9.5, $result->duration);
        $this->assertSame(1080, $result->width);
        $this->assertSame(1920, $result->height);
        $this->assertSame("projects/{$video->content_project_id}/renders/{$video->id}.mp4", $result->path);
    }

    public function test_it_stores_the_rendered_file_on_the_configured_disk(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeFfmpegProcesses();

        $video = $this->buildVideo();
        (new FfmpegVideoRenderer)->render($video);

        Storage::disk(config('filesystems.default'))
            ->assertExists("projects/{$video->content_project_id}/renders/{$video->id}.mp4");
    }

    public function test_it_does_not_add_a_music_input_when_no_music_asset_is_set(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeFfmpegProcesses();

        $video = $this->buildVideo(withMusic: false);
        (new FfmpegVideoRenderer)->render($video);

        // 'music.mp3' can only ever appear in a command via mixAndBurn's music
        // input — asserting no recorded process contains it at all is a precise
        // (not just "some call happens to lack it") check on that one call site.
        Process::assertDidntRun(function ($process) {
            return str_contains(implode(' ', $process->command), 'music.mp3');
        });
    }

    public function test_it_adds_a_music_input_and_amix_filter_when_a_music_asset_is_set(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeFfmpegProcesses();

        $video = $this->buildVideo(withMusic: true);
        (new FfmpegVideoRenderer)->render($video);

        Process::assertRan(function ($process) {
            $joined = implode(' ', $process->command);

            return str_contains($joined, 'music.mp3') && str_contains($joined, 'amix');
        });
    }

    public function test_it_throws_when_scene_normalization_fails(): void
    {
        Storage::fake(config('filesystems.default'));
        Process::fake(['*' => Process::result(errorOutput: 'no such filter', exitCode: 1)]);

        $video = $this->buildVideo();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Scene 0 normalization failed');

        (new FfmpegVideoRenderer)->render($video);
    }
}

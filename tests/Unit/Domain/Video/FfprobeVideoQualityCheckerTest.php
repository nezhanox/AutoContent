<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\Providers\FfprobeVideoQualityChecker;
use App\Models\ContentProject;
use App\Models\Video;
use App\Models\VideoScene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FfprobeVideoQualityCheckerTest extends TestCase
{
    use RefreshDatabase;

    private function buildRenderedVideo(): Video
    {
        $project = ContentProject::factory()->create();
        $video = Video::factory()->create([
            'content_project_id' => $project->id,
            'file_path' => "projects/{$project->id}/renders/video.mp4",
        ]);
        VideoScene::factory()->create(['video_id' => $video->id, 'duration' => 5]);
        VideoScene::factory()->create(['video_id' => $video->id, 'duration' => 4]);

        Storage::disk(config('filesystems.default'))->put($video->file_path, 'fake-rendered-bytes');

        return $video->fresh('scenes');
    }

    private function fakeProbeAndBlackdetect(array $probeOverrides = [], string $blackdetectErrorOutput = ''): void
    {
        $probe = array_merge([
            'format' => ['duration' => '9.20'],
            'streams' => [
                ['codec_type' => 'video', 'width' => 1080, 'height' => 1920],
                ['codec_type' => 'audio'],
            ],
        ], $probeOverrides);

        Process::fake(function ($process) use ($probe, $blackdetectErrorOutput) {
            if (in_array('-show_streams', $process->command, true)) {
                return Process::result(output: json_encode($probe));
            }

            return Process::result(errorOutput: $blackdetectErrorOutput);
        });
    }

    public function test_it_passes_when_all_checks_succeed(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeProbeAndBlackdetect();

        $video = $this->buildRenderedVideo();
        $result = (new FfprobeVideoQualityChecker)->check($video);

        $this->assertTrue($result->passed);
        $this->assertTrue($result->checks['has_video_stream']);
        $this->assertTrue($result->checks['has_audio_stream']);
        $this->assertTrue($result->checks['resolution_matches']);
        $this->assertTrue($result->checks['duration_within_tolerance']);
        $this->assertTrue($result->checks['not_excessively_black']);
    }

    public function test_it_fails_resolution_check_when_dimensions_do_not_match(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeProbeAndBlackdetect(['streams' => [
            ['codec_type' => 'video', 'width' => 640, 'height' => 480],
            ['codec_type' => 'audio'],
        ]]);

        $video = $this->buildRenderedVideo();
        $result = (new FfprobeVideoQualityChecker)->check($video);

        $this->assertFalse($result->passed);
        $this->assertFalse($result->checks['resolution_matches']);
    }

    public function test_it_fails_audio_check_when_there_is_no_audio_stream(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeProbeAndBlackdetect(['streams' => [
            ['codec_type' => 'video', 'width' => 1080, 'height' => 1920],
        ]]);

        $video = $this->buildRenderedVideo();
        $result = (new FfprobeVideoQualityChecker)->check($video);

        $this->assertFalse($result->passed);
        $this->assertFalse($result->checks['has_audio_stream']);
    }

    public function test_it_fails_duration_check_when_outside_tolerance(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeProbeAndBlackdetect(['format' => ['duration' => '30.0']]);

        $video = $this->buildRenderedVideo();
        $result = (new FfprobeVideoQualityChecker)->check($video);

        $this->assertFalse($result->passed);
        $this->assertFalse($result->checks['duration_within_tolerance']);
    }

    public function test_it_fails_black_frame_check_when_blackdetect_reports_black_start(): void
    {
        Storage::fake(config('filesystems.default'));
        $this->fakeProbeAndBlackdetect(blackdetectErrorOutput: '[blackdetect @ 0x0] black_start:0 black_end:5 black_duration:5');

        $video = $this->buildRenderedVideo();
        $result = (new FfprobeVideoQualityChecker)->check($video);

        $this->assertFalse($result->passed);
        $this->assertFalse($result->checks['not_excessively_black']);
    }
}

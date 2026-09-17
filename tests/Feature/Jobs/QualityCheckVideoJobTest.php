<?php

namespace Tests\Feature\Jobs;

use App\Domain\Video\Providers\FakeVideoQualityChecker;
use App\Domain\Video\QualityCheckResult;
use App\Domain\Video\VideoQualityCheckerInterface;
use App\Jobs\QualityCheckVideoJob;
use App\Models\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualityCheckVideoJobTest extends TestCase
{
    use RefreshDatabase;

    private function bindFakeChecker(?QualityCheckResult $result = null): void
    {
        $this->app->bind(VideoQualityCheckerInterface::class, function () use ($result) {
            $fake = new FakeVideoQualityChecker;

            return $result !== null ? $fake->respondWith($result) : $fake;
        });
    }

    public function test_it_saves_the_quality_report_on_a_rendered_video(): void
    {
        $this->bindFakeChecker(new QualityCheckResult(
            passed: false,
            checks: ['has_video_stream' => true, 'resolution_matches' => false],
            notes: ['resolution mismatch'],
        ));

        $video = Video::factory()->create(['status' => VideoStatus::Rendered, 'file_path' => 'projects/1/renders/1.mp4']);

        app()->call([new QualityCheckVideoJob($video->id), 'handle']);

        $fresh = $video->fresh();
        $this->assertFalse($fresh->quality_passed);
        $this->assertSame(['has_video_stream' => true, 'resolution_matches' => false], $fresh->quality_report['checks']);
        $this->assertSame(['resolution mismatch'], $fresh->quality_report['notes']);
    }

    public function test_it_is_a_no_op_when_the_video_is_not_rendered(): void
    {
        $this->bindFakeChecker();

        $video = Video::factory()->create(['status' => VideoStatus::AssetsReady]);

        app()->call([new QualityCheckVideoJob($video->id), 'handle']);

        $this->assertNull($video->fresh()->quality_passed);
    }

    public function test_re_running_it_overwrites_the_previous_report(): void
    {
        $video = Video::factory()->create([
            'status' => VideoStatus::Rendered,
            'file_path' => 'projects/1/renders/1.mp4',
            'quality_passed' => false,
            'quality_report' => ['checks' => ['has_video_stream' => false]],
        ]);

        $this->bindFakeChecker(new QualityCheckResult(passed: true, checks: ['has_video_stream' => true]));

        app()->call([new QualityCheckVideoJob($video->id), 'handle']);

        $fresh = $video->fresh();
        $this->assertTrue($fresh->quality_passed);
        $this->assertSame(['has_video_stream' => true], $fresh->quality_report['checks']);
    }
}

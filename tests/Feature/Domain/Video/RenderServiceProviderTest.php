<?php

namespace Tests\Feature\Domain\Video;

use App\Domain\Video\Providers\FfmpegVideoRenderer;
use App\Domain\Video\Providers\FfprobeVideoQualityChecker;
use App\Domain\Video\VideoQualityCheckerInterface;
use App\Domain\Video\VideoRendererInterface;
use Tests\TestCase;

class RenderServiceProviderTest extends TestCase
{
    public function test_video_renderer_interface_resolves_to_ffmpeg_renderer(): void
    {
        $this->assertInstanceOf(FfmpegVideoRenderer::class, $this->app->make(VideoRendererInterface::class));
    }

    public function test_video_quality_checker_interface_resolves_to_ffprobe_checker(): void
    {
        $this->assertInstanceOf(FfprobeVideoQualityChecker::class, $this->app->make(VideoQualityCheckerInterface::class));
    }
}

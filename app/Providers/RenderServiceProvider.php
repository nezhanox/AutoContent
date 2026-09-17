<?php

namespace App\Providers;

use App\Domain\Video\Providers\FfmpegVideoRenderer;
use App\Domain\Video\Providers\FfprobeVideoQualityChecker;
use App\Domain\Video\VideoQualityCheckerInterface;
use App\Domain\Video\VideoRendererInterface;
use Illuminate\Support\ServiceProvider;

class RenderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VideoRendererInterface::class, FfmpegVideoRenderer::class);
        $this->app->bind(VideoQualityCheckerInterface::class, FfprobeVideoQualityChecker::class);
    }
}

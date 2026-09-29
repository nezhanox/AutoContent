<?php

namespace App\Providers;

use App\Domain\Source\Providers\YtDlpDownloader;
use App\Domain\Source\Support\ClipValidator;
use App\Domain\Source\YoutubeDownloaderInterface;
use Illuminate\Support\ServiceProvider;

class SourceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(YoutubeDownloaderInterface::class, YtDlpDownloader::class);
        $this->app->bind(ClipValidator::class, fn () => new ClipValidator((float) config('clips.padding_seconds')));
    }
}

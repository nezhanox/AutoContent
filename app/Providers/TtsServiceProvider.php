<?php

namespace App\Providers;

use App\Domain\Video\Providers\ElevenLabsTtsProvider;
use App\Domain\Video\TtsProviderInterface;
use Illuminate\Support\ServiceProvider;

class TtsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TtsProviderInterface::class, ElevenLabsTtsProvider::class);
    }
}

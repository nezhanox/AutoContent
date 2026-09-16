<?php

namespace App\Providers;

use App\Domain\Video\Providers\WhisperCliTranscriptionProvider;
use App\Domain\Video\TranscriptionProviderInterface;
use Illuminate\Support\ServiceProvider;

class TranscriptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TranscriptionProviderInterface::class, WhisperCliTranscriptionProvider::class);
    }
}

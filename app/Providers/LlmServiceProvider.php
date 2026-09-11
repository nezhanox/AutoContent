<?php

namespace App\Providers;

use App\Domain\Llm\LlmManager;
use App\Domain\Llm\LlmManagerInterface;
use Illuminate\Support\ServiceProvider;

class LlmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LlmManagerInterface::class, LlmManager::class);
    }
}

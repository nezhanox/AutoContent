<?php

namespace App\Providers;

use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\Providers\LocalAssetProvider;
use Illuminate\Support\ServiceProvider;

class AssetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AssetProviderInterface::class, LocalAssetProvider::class);
    }
}

<?php

namespace App\Providers;

use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\Providers\ChainedAssetProvider;
use App\Domain\Video\Providers\LocalAssetProvider;
use App\Domain\Video\Providers\PexelsAssetProvider;
use App\Domain\Video\Providers\PixabayAssetProvider;
use Illuminate\Support\ServiceProvider;

class AssetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AssetProviderInterface::class, function (): ChainedAssetProvider {
            $drivers = [
                'pixabay' => fn (): PixabayAssetProvider => new PixabayAssetProvider,
                'pexels' => fn (): PexelsAssetProvider => new PexelsAssetProvider,
                'local' => fn (): LocalAssetProvider => new LocalAssetProvider,
            ];

            $providers = array_map(
                fn (string $name) => $drivers[$name](),
                config('assets.chain', ['local']),
            );

            return new ChainedAssetProvider($providers);
        });
    }
}

<?php

namespace App\Providers;

use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\Providers\ChainedAssetProvider;
use App\Domain\Video\Providers\LocalAssetProvider;
use App\Domain\Video\Providers\PexelsAssetProvider;
use App\Domain\Video\Providers\PixabayAssetProvider;
use App\Domain\Video\Providers\WikimediaAssetProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AssetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AssetProviderInterface::class, function (): ChainedAssetProvider {
            $drivers = [
                'wikimedia' => fn (): WikimediaAssetProvider => new WikimediaAssetProvider,
                'pixabay' => fn (): PixabayAssetProvider => new PixabayAssetProvider,
                'pexels' => fn (): PexelsAssetProvider => new PexelsAssetProvider,
                'local' => fn (): LocalAssetProvider => new LocalAssetProvider,
            ];

            $providers = array_map(
                function (string $name) use ($drivers) {
                    if (! array_key_exists($name, $drivers)) {
                        throw new InvalidArgumentException("Unknown asset provider [{$name}] in config('assets.chain').");
                    }

                    return $drivers[$name]();
                },
                config('assets.chain', ['local']),
            );

            return new ChainedAssetProvider($providers);
        });
    }
}

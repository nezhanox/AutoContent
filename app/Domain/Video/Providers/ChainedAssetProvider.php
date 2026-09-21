<?php

namespace App\Domain\Video\Providers;

use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\AssetSearchOptions;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ChainedAssetProvider implements AssetProviderInterface
{
    /**
     * @param  array<int, AssetProviderInterface>  $providers
     */
    public function __construct(private readonly array $providers) {}

    /**
     * @return array<int, \App\Models\MediaAsset>
     */
    public function search(string $query, AssetSearchOptions $options): array
    {
        foreach ($this->providers as $provider) {
            try {
                $results = $provider->search($query, $options);
            } catch (Throwable $exception) {
                Log::warning('asset provider threw during search', [
                    'provider' => $provider::class,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            if ($results !== []) {
                return $results;
            }
        }

        return [];
    }
}

<?php

namespace Tests\Feature\Domain\Video;

use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\AssetSearchOptions;
use App\Domain\Video\Providers\ChainedAssetProvider;
use App\Models\Enums\MediaAssetType;
use App\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssetServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_asset_provider_interface_resolves_to_a_chained_provider(): void
    {
        $this->assertInstanceOf(ChainedAssetProvider::class, $this->app->make(AssetProviderInterface::class));
    }

    public function test_the_chain_falls_back_to_local_results_when_stock_providers_return_nothing(): void
    {
        // No PIXABAY_API_KEY/PEXELS_API_KEY in the test environment. Fake
        // both remote endpoints to return zero hits so the chain falls
        // through to `local` without ever making a real network call.
        Http::fake([
            'pixabay.com/api/*' => Http::response(['hits' => []], 200),
            'api.pexels.com/*' => Http::response(['photos' => []], 200),
        ]);

        $localAsset = MediaAsset::factory()->create([
            'type' => MediaAssetType::Image,
            'provider' => 'local',
            'metadata' => ['tags' => ['stoic', 'statue']],
        ]);

        $provider = $this->app->make(AssetProviderInterface::class);

        $results = $provider->search('stoic statue', new AssetSearchOptions(
            types: [MediaAssetType::Image],
        ));

        $this->assertCount(1, $results);
        $this->assertSame($localAsset->id, $results[0]->id);
    }
}

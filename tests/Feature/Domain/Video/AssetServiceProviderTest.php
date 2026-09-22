<?php

namespace Tests\Feature\Domain\Video;

use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\AssetSearchOptions;
use App\Domain\Video\Providers\ChainedAssetProvider;
use App\Models\Enums\MediaAssetType;
use App\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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
        // all three remote endpoints to return zero hits so the chain falls
        // through to `local` without ever making a real network call.
        Http::fake([
            'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => []]], 200),
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

    public function test_wikimedia_is_tried_before_the_commercial_stock_providers(): void
    {
        Storage::fake(config('filesystems.default'));

        Http::fake([
            'commons.wikimedia.org/*' => Http::response([
                'query' => [
                    'pages' => [
                        '111' => [
                            'pageid' => 111,
                            'title' => 'File:Marble statue of a Stoic philosopher.jpg',
                            'imageinfo' => [[
                                'url' => 'https://upload.wikimedia.test/original/111.jpg',
                                'mime' => 'image/jpeg',
                                'thumburl' => 'https://upload.wikimedia.test/thumb/111.jpg',
                                'thumbwidth' => 1600,
                                'thumbheight' => 2133,
                            ]],
                        ],
                    ],
                ],
            ], 200),
            'upload.wikimedia.test/*' => Http::response('fake-jpg-bytes', 200),
        ]);

        $provider = $this->app->make(AssetProviderInterface::class);

        $results = $provider->search('marble statue stoic philosopher', new AssetSearchOptions(
            types: [MediaAssetType::Image],
        ));

        $this->assertCount(1, $results);
        $this->assertSame('wikimedia', $results[0]->provider);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'pixabay.com')
            || str_contains($request->url(), 'api.pexels.com'));
    }
}

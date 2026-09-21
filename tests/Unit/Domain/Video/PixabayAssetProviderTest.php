<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\AssetSearchOptions;
use App\Domain\Video\Providers\PixabayAssetProvider;
use App\Models\Enums\MediaAssetType;
use App\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PixabayAssetProviderTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): void
    {
        config()->set('assets.providers.pixabay.api_key', 'test-key');
        config()->set('assets.providers.pixabay.base_url', 'https://pixabay.com/api');
    }

    public function test_it_downloads_and_stores_a_matching_photo(): void
    {
        $this->configure();
        Storage::fake(config('filesystems.default'));

        Http::fake([
            'pixabay.com/api/*' => Http::response([
                'hits' => [
                    [
                        'id' => 111,
                        'largeImageURL' => 'https://cdn.pixabay.test/111.jpg',
                        'imageWidth' => 1080,
                        'imageHeight' => 1920,
                    ],
                ],
            ], 200),
            'cdn.pixabay.test/*' => Http::response('fake-jpg-bytes', 200),
        ]);

        $provider = new PixabayAssetProvider;
        $results = $provider->search('philosophy statue', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertCount(1, $results);
        $this->assertSame(MediaAssetType::Image, $results[0]->type);
        $this->assertSame('pixabay', $results[0]->provider);
        $this->assertSame('assets/stock/pixabay/111.jpg', $results[0]->path);
        Storage::disk(config('filesystems.default'))->assertExists('assets/stock/pixabay/111.jpg');
    }

    public function test_it_searches_the_video_endpoint_when_a_video_type_is_requested(): void
    {
        $this->configure();
        Storage::fake(config('filesystems.default'));

        Http::fake([
            'pixabay.com/api/videos/*' => Http::response([
                'hits' => [
                    [
                        'id' => 222,
                        'duration' => 12,
                        'videos' => [
                            'large' => ['url' => 'https://cdn.pixabay.test/222.mp4', 'width' => 1080, 'height' => 1920],
                        ],
                    ],
                ],
            ], 200),
            'cdn.pixabay.test/*' => Http::response('fake-mp4-bytes', 200),
        ]);

        $provider = new PixabayAssetProvider;
        $results = $provider->search('marble statue', new AssetSearchOptions(types: [MediaAssetType::Video]));

        $this->assertCount(1, $results);
        $this->assertSame(MediaAssetType::Video, $results[0]->type);
        $this->assertSame('assets/stock/pixabay/222.mp4', $results[0]->path);
    }

    public function test_it_returns_an_empty_array_when_the_search_request_fails(): void
    {
        $this->configure();

        Http::fake([
            'pixabay.com/api/*' => Http::response('server error', 500),
        ]);

        $provider = new PixabayAssetProvider;
        $results = $provider->search('anything', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertSame([], $results);
    }

    public function test_it_returns_an_empty_array_and_makes_no_request_for_unsupported_types(): void
    {
        $this->configure();
        Http::fake();

        $provider = new PixabayAssetProvider;
        $results = $provider->search('anything', new AssetSearchOptions(types: [MediaAssetType::Subtitle]));

        $this->assertSame([], $results);
        Http::assertNothingSent();
    }

    public function test_it_reuses_an_existing_media_asset_instead_of_downloading_again(): void
    {
        $this->configure();
        Storage::fake(config('filesystems.default'));

        $existing = MediaAsset::factory()->create([
            'type' => MediaAssetType::Image,
            'provider' => 'pixabay',
            'path' => 'assets/stock/pixabay/111.jpg',
        ]);

        Http::fake([
            'pixabay.com/api/*' => Http::response([
                'hits' => [
                    ['id' => 111, 'largeImageURL' => 'https://cdn.pixabay.test/111.jpg', 'imageWidth' => 1080, 'imageHeight' => 1920],
                ],
            ], 200),
        ]);

        $provider = new PixabayAssetProvider;
        $results = $provider->search('philosophy statue', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertCount(1, $results);
        $this->assertSame($existing->id, $results[0]->id);
        Http::assertSentCount(1);
    }

    public function test_it_excludes_given_asset_ids(): void
    {
        $this->configure();
        Storage::fake(config('filesystems.default'));

        $existing = MediaAsset::factory()->create([
            'type' => MediaAssetType::Image,
            'provider' => 'pixabay',
            'path' => 'assets/stock/pixabay/111.jpg',
        ]);

        Http::fake([
            'pixabay.com/api/*' => Http::response([
                'hits' => [
                    ['id' => 111, 'largeImageURL' => 'https://cdn.pixabay.test/111.jpg', 'imageWidth' => 1080, 'imageHeight' => 1920],
                ],
            ], 200),
        ]);

        $provider = new PixabayAssetProvider;
        $results = $provider->search('philosophy statue', new AssetSearchOptions(
            types: [MediaAssetType::Image],
            excludeAssetIds: [$existing->id],
        ));

        $this->assertSame([], $results);
    }
}

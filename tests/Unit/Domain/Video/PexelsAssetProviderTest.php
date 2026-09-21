<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\AssetSearchOptions;
use App\Domain\Video\Providers\PexelsAssetProvider;
use App\Models\Enums\MediaAssetType;
use App\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PexelsAssetProviderTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): void
    {
        config()->set('assets.providers.pexels.api_key', 'test-key');
        config()->set('assets.providers.pexels.base_url', 'https://api.pexels.com');
    }

    public function test_it_downloads_and_stores_a_matching_photo(): void
    {
        $this->configure();
        Storage::fake(config('filesystems.default'));

        Http::fake([
            'api.pexels.com/v1/search*' => Http::response([
                'photos' => [
                    [
                        'id' => 333,
                        'width' => 1080,
                        'height' => 1920,
                        'src' => ['original' => 'https://cdn.pexels.test/333.jpg'],
                    ],
                ],
            ], 200),
            'cdn.pexels.test/*' => Http::response('fake-jpg-bytes', 200),
        ]);

        $provider = new PexelsAssetProvider;
        $results = $provider->search('stoic statue', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertCount(1, $results);
        $this->assertSame(MediaAssetType::Image, $results[0]->type);
        $this->assertSame('pexels', $results[0]->provider);
        $this->assertSame('assets/stock/pexels/333.jpg', $results[0]->path);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'test-key');
        });
    }

    public function test_it_picks_the_largest_video_file_when_a_video_type_is_requested(): void
    {
        $this->configure();
        Storage::fake(config('filesystems.default'));

        Http::fake([
            'api.pexels.com/videos/search*' => Http::response([
                'videos' => [
                    [
                        'id' => 444,
                        'duration' => 9,
                        'video_files' => [
                            ['link' => 'https://cdn.pexels.test/444-small.mp4', 'width' => 360, 'height' => 640],
                            ['link' => 'https://cdn.pexels.test/444-large.mp4', 'width' => 1080, 'height' => 1920],
                        ],
                    ],
                ],
            ], 200),
            'cdn.pexels.test/*' => Http::response('fake-mp4-bytes', 200),
        ]);

        $provider = new PexelsAssetProvider;
        $results = $provider->search('marble statue', new AssetSearchOptions(types: [MediaAssetType::Video]));

        $this->assertCount(1, $results);
        $this->assertSame(MediaAssetType::Video, $results[0]->type);
        $this->assertSame('assets/stock/pexels/444.mp4', $results[0]->path);
    }

    public function test_it_falls_back_to_photos_when_video_search_returns_no_hits_for_a_mixed_type_request(): void
    {
        $this->configure();
        Storage::fake(config('filesystems.default'));

        Http::fake([
            'api.pexels.com/videos/search*' => Http::response(['videos' => []], 200),
            'api.pexels.com/v1/search*' => Http::response([
                'photos' => [
                    [
                        'id' => 777,
                        'width' => 1080,
                        'height' => 1920,
                        'src' => ['original' => 'https://cdn.pexels.test/777.jpg'],
                    ],
                ],
            ], 200),
            'cdn.pexels.test/*' => Http::response('fake-jpg-bytes', 200),
        ]);

        $provider = new PexelsAssetProvider;
        $results = $provider->search('stoic statue', new AssetSearchOptions(
            types: [MediaAssetType::Video, MediaAssetType::Image],
        ));

        $this->assertCount(1, $results);
        $this->assertSame(MediaAssetType::Image, $results[0]->type);
        $this->assertSame('assets/stock/pexels/777.jpg', $results[0]->path);
    }

    public function test_it_returns_an_empty_array_when_the_search_request_fails(): void
    {
        $this->configure();

        Http::fake([
            'api.pexels.com/v1/search*' => Http::response('unauthorized', 401),
        ]);

        $provider = new PexelsAssetProvider;
        $results = $provider->search('anything', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertSame([], $results);
    }

    public function test_it_returns_an_empty_array_and_makes_no_request_for_unsupported_types(): void
    {
        $this->configure();
        Http::fake();

        $provider = new PexelsAssetProvider;
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
            'provider' => 'pexels',
            'path' => 'assets/stock/pexels/333.jpg',
        ]);

        Http::fake([
            'api.pexels.com/v1/search*' => Http::response([
                'photos' => [
                    ['id' => 333, 'width' => 1080, 'height' => 1920, 'src' => ['original' => 'https://cdn.pexels.test/333.jpg']],
                ],
            ], 200),
        ]);

        $provider = new PexelsAssetProvider;
        $results = $provider->search('stoic statue', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertCount(1, $results);
        $this->assertSame($existing->id, $results[0]->id);
        Http::assertSentCount(1);
    }
}

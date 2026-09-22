<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\AssetSearchOptions;
use App\Domain\Video\Providers\WikimediaAssetProvider;
use App\Models\Enums\MediaAssetType;
use App\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WikimediaAssetProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_downloads_and_stores_a_matching_photo(): void
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

        $provider = new WikimediaAssetProvider;
        $results = $provider->search('marble statue stoic philosopher', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertCount(1, $results);
        $this->assertSame(MediaAssetType::Image, $results[0]->type);
        $this->assertSame('wikimedia', $results[0]->provider);
        $this->assertSame('assets/stock/wikimedia/111.jpg', $results[0]->path);
        Storage::disk(config('filesystems.default'))->assertExists('assets/stock/wikimedia/111.jpg');
    }

    public function test_it_returns_an_empty_array_and_makes_no_request_for_unsupported_types(): void
    {
        Http::fake();

        $provider = new WikimediaAssetProvider;
        $results = $provider->search('anything', new AssetSearchOptions(types: [MediaAssetType::Subtitle]));

        $this->assertSame([], $results);
        Http::assertNothingSent();
    }

    public function test_it_returns_an_empty_array_when_the_search_request_fails(): void
    {
        Http::fake([
            'commons.wikimedia.org/*' => Http::response('server error', 500),
        ]);

        $provider = new WikimediaAssetProvider;
        $results = $provider->search('anything', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertSame([], $results);
    }

    public function test_it_skips_pages_without_a_supported_image_mime_type(): void
    {
        Storage::fake(config('filesystems.default'));

        Http::fake([
            'commons.wikimedia.org/*' => Http::response([
                'query' => [
                    'pages' => [
                        '222' => [
                            'pageid' => 222,
                            'title' => 'File:Some diagram.svg',
                            'imageinfo' => [[
                                'url' => 'https://upload.wikimedia.test/original/222.svg',
                                'mime' => 'image/svg+xml',
                            ]],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $provider = new WikimediaAssetProvider;
        $results = $provider->search('anything', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertSame([], $results);
    }

    public function test_it_excludes_given_asset_ids(): void
    {
        Storage::fake(config('filesystems.default'));

        $existing = MediaAsset::factory()->create([
            'type' => MediaAssetType::Image,
            'provider' => 'wikimedia',
            'path' => 'assets/stock/wikimedia/111.jpg',
        ]);

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
        ]);

        $provider = new WikimediaAssetProvider;
        $results = $provider->search('marble statue stoic philosopher', new AssetSearchOptions(
            types: [MediaAssetType::Image],
            excludeAssetIds: [$existing->id],
        ));

        $this->assertSame([], $results);
    }

    public function test_it_reuses_an_existing_media_asset_instead_of_downloading_again(): void
    {
        Storage::fake(config('filesystems.default'));

        $existing = MediaAsset::factory()->create([
            'type' => MediaAssetType::Image,
            'provider' => 'wikimedia',
            'path' => 'assets/stock/wikimedia/111.jpg',
        ]);

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
        ]);

        $provider = new WikimediaAssetProvider;
        $results = $provider->search('marble statue stoic philosopher', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertCount(1, $results);
        $this->assertSame($existing->id, $results[0]->id);
        Http::assertSentCount(1);
    }
}

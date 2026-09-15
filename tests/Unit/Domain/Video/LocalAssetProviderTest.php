<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\AssetSearchOptions;
use App\Domain\Video\Providers\LocalAssetProvider;
use App\Models\Enums\MediaAssetType;
use App\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalAssetProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_asset_with_the_highest_tag_overlap_first(): void
    {
        $weak = MediaAsset::factory()->create([
            'type' => MediaAssetType::Video,
            'metadata' => ['tags' => ['server']],
        ]);
        $strong = MediaAsset::factory()->create([
            'type' => MediaAssetType::Video,
            'metadata' => ['tags' => ['ai', 'server', 'room']],
        ]);

        $provider = new LocalAssetProvider;
        $results = $provider->search('AI server room', new AssetSearchOptions(types: [MediaAssetType::Video]));

        $this->assertCount(2, $results);
        $this->assertSame($strong->id, $results[0]->id);
        $this->assertSame($weak->id, $results[1]->id);
    }

    public function test_it_filters_by_type(): void
    {
        MediaAsset::factory()->create([
            'type' => MediaAssetType::Image,
            'metadata' => ['tags' => ['ai', 'server']],
        ]);
        $video = MediaAsset::factory()->create([
            'type' => MediaAssetType::Video,
            'metadata' => ['tags' => ['ai', 'server']],
        ]);

        $provider = new LocalAssetProvider;
        $results = $provider->search('ai server', new AssetSearchOptions(types: [MediaAssetType::Video]));

        $this->assertCount(1, $results);
        $this->assertSame($video->id, $results[0]->id);
    }

    public function test_it_excludes_given_asset_ids(): void
    {
        $first = MediaAsset::factory()->create([
            'type' => MediaAssetType::Video,
            'metadata' => ['tags' => ['ai']],
        ]);
        $second = MediaAsset::factory()->create([
            'type' => MediaAssetType::Video,
            'metadata' => ['tags' => ['ai']],
        ]);

        $provider = new LocalAssetProvider;
        $results = $provider->search('ai', new AssetSearchOptions(
            types: [MediaAssetType::Video],
            excludeAssetIds: [$first->id],
        ));

        $this->assertCount(1, $results);
        $this->assertSame($second->id, $results[0]->id);
    }

    public function test_it_returns_an_empty_array_when_nothing_matches(): void
    {
        MediaAsset::factory()->create([
            'type' => MediaAssetType::Video,
            'metadata' => ['tags' => ['cat']],
        ]);

        $provider = new LocalAssetProvider;
        $results = $provider->search('server room', new AssetSearchOptions(types: [MediaAssetType::Video]));

        $this->assertSame([], $results);
    }

    public function test_it_respects_max_results(): void
    {
        MediaAsset::factory()->count(3)->create([
            'type' => MediaAssetType::Video,
            'metadata' => ['tags' => ['ai']],
        ]);

        $provider = new LocalAssetProvider;
        $results = $provider->search('ai', new AssetSearchOptions(types: [MediaAssetType::Video], maxResults: 2));

        $this->assertCount(2, $results);
    }
}

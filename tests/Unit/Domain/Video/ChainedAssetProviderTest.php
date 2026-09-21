<?php

namespace Tests\Unit\Domain\Video;

use App\Domain\Video\AssetProviderInterface;
use App\Domain\Video\AssetSearchOptions;
use App\Domain\Video\Providers\ChainedAssetProvider;
use App\Models\Enums\MediaAssetType;
use App\Models\MediaAsset;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class ChainedAssetProviderTest extends TestCase
{
    public function test_it_stops_at_the_first_provider_that_returns_a_non_empty_result(): void
    {
        $asset = new MediaAsset(['type' => MediaAssetType::Image]);

        $empty = $this->providerReturning([]);
        $winner = $this->providerReturning([$asset]);
        $shouldNotBeCalled = new class implements AssetProviderInterface
        {
            public function search(string $query, AssetSearchOptions $options): array
            {
                throw new LogicException('this provider should not have been called');
            }
        };

        $chain = new ChainedAssetProvider([$empty, $winner, $shouldNotBeCalled]);
        $results = $chain->search('query', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertSame([$asset], $results);
    }

    public function test_it_falls_through_to_the_next_provider_when_one_throws(): void
    {
        $asset = new MediaAsset(['type' => MediaAssetType::Image]);

        $throwing = new class implements AssetProviderInterface
        {
            public function search(string $query, AssetSearchOptions $options): array
            {
                throw new RuntimeException('provider unavailable');
            }
        };

        $chain = new ChainedAssetProvider([$throwing, $this->providerReturning([$asset])]);
        $results = $chain->search('query', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertSame([$asset], $results);
    }

    public function test_it_returns_an_empty_array_when_every_provider_is_empty(): void
    {
        $chain = new ChainedAssetProvider([$this->providerReturning([]), $this->providerReturning([])]);
        $results = $chain->search('query', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertSame([], $results);
    }

    public function test_it_returns_an_empty_array_when_every_provider_throws(): void
    {
        $throwing = new class implements AssetProviderInterface
        {
            public function search(string $query, AssetSearchOptions $options): array
            {
                throw new RuntimeException('provider unavailable');
            }
        };

        $chain = new ChainedAssetProvider([$throwing, $throwing]);
        $results = $chain->search('query', new AssetSearchOptions(types: [MediaAssetType::Image]));

        $this->assertSame([], $results);
    }

    /**
     * @param  array<int, MediaAsset>  $result
     */
    private function providerReturning(array $result): AssetProviderInterface
    {
        return new class($result) implements AssetProviderInterface
        {
            public function __construct(private readonly array $result) {}

            public function search(string $query, AssetSearchOptions $options): array
            {
                return $this->result;
            }
        };
    }
}

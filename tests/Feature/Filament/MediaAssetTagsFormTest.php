<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\MediaAssets\Pages\CreateMediaAsset;
use App\Filament\Resources\MediaAssets\Pages\EditMediaAsset;
use App\Models\Enums\MediaAssetType;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MediaAssetTagsFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_media_asset_saves_tags_into_metadata(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateMediaAsset::class)
            ->fillForm([
                'type' => MediaAssetType::Video->value,
                'provider' => 'local',
                'path' => 'assets/server-room.mp4',
                'mime_type' => 'video/mp4',
                'hash' => str_repeat('a', 64),
                'metadata.tags' => ['ai', 'server', 'room'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $asset = MediaAsset::sole();
        $this->assertSame(['ai', 'server', 'room'], $asset->metadata['tags']);
    }

    public function test_editing_a_media_asset_updates_its_tags(): void
    {
        $this->actingAs(User::factory()->create());

        $asset = MediaAsset::factory()->create(['metadata' => ['tags' => ['old']]]);

        Livewire::test(EditMediaAsset::class, ['record' => $asset->getRouteKey()])
            ->fillForm(['metadata.tags' => ['new', 'tags']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['new', 'tags'], $asset->fresh()->metadata['tags']);
    }
}

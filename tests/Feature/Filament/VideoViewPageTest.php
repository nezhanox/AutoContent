<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Videos\Pages\ViewVideo;
use App\Models\Enums\MediaAssetType;
use App\Models\Enums\VideoSceneType;
use App\Models\MediaAsset;
use App\Models\Script;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoScene;
use App\Models\Voiceover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class VideoViewPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_view_page_shows_the_script_content(): void
    {
        $this->actingAs(User::factory()->create());

        $script = Script::factory()->create(['content' => 'This is the narration text.']);
        $video = Video::factory()->create(['script_id' => $script->id]);

        Livewire::test(ViewVideo::class, ['record' => $video->getRouteKey()])
            ->assertSuccessful()
            ->assertSchemaStateSet(['script_preview' => 'This is the narration text.'])
            ->assertSee('This is the narration text.', escape: true, stripInitialData: false);
    }

    public function test_the_view_page_shows_the_scenes_and_subtitles(): void
    {
        $this->actingAs(User::factory()->create());

        Storage::fake(config('filesystems.default'));
        Storage::disk(config('filesystems.default'))->put('subtitles/video.srt', 'Hello from the subtitle file.');

        $subtitle = MediaAsset::factory()->create([
            'type' => MediaAssetType::Subtitle,
            'path' => 'subtitles/video.srt',
        ]);
        $video = Video::factory()->create(['subtitle_id' => $subtitle->id]);
        VideoScene::factory()->create([
            'video_id' => $video->id,
            'order' => 1,
            'type' => VideoSceneType::Broll,
            'duration' => 4,
            'visual_query' => 'sunrise over mountains',
        ]);

        Livewire::test(ViewVideo::class, ['record' => $video->getRouteKey()])
            ->assertSuccessful()
            ->assertSchemaStateSet([
                'scenes_preview' => '#1 [broll] 4s — sunrise over mountains',
                'subtitles_preview' => 'Hello from the subtitle file.',
            ]);
    }

    public function test_the_view_page_links_to_the_voiceover_and_the_rendered_video(): void
    {
        $this->actingAs(User::factory()->create());

        $video = Video::factory()->create(['file_path' => 'videos/final.mp4']);
        Voiceover::factory()->create([
            'video_id' => $video->id,
            'file_path' => 'voiceovers/voice.mp3',
        ]);

        $disk = Storage::disk(config('filesystems.default'));

        Livewire::test(ViewVideo::class, ['record' => $video->getRouteKey()])
            ->assertSuccessful()
            ->assertSeeHtml('<a href="'.$disk->url('voiceovers/voice.mp3').'" target="_blank" rel="noopener">Play voiceover</a>')
            ->assertSeeHtml('<a href="'.$disk->url('videos/final.mp4').'" target="_blank" rel="noopener">Open rendered video</a>');
    }
}

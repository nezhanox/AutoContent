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
        $this->freezeTime();

        $video = Video::factory()->create(['file_path' => 'videos/final.mp4']);
        Voiceover::factory()->create([
            'video_id' => $video->id,
            'file_path' => 'voiceovers/voice.mp3',
        ]);

        $disk = Storage::disk(config('filesystems.default'));
        $voiceoverUrl = $disk->temporaryUrl('voiceovers/voice.mp3', now()->addMinutes(30));
        $renderUrl = $disk->temporaryUrl('videos/final.mp4', now()->addMinutes(30));

        // The default disk is private, so a plain url() would not be servable — the links
        // must be signed temporary URLs.
        $this->assertStringContainsString('signature=', $voiceoverUrl);
        $this->assertStringContainsString('signature=', $renderUrl);
        $this->assertNotSame($disk->url('voiceovers/voice.mp3'), $voiceoverUrl);
        $this->assertNotSame($disk->url('videos/final.mp4'), $renderUrl);

        $html = Livewire::test(ViewVideo::class, ['record' => $video->getRouteKey()])
            ->assertSuccessful()
            ->html();

        $this->assertStringContainsString(
            '<audio controls preload="metadata" style="width: 100%; max-width: 480px;" src="'.$voiceoverUrl.'"></audio>',
            $html,
        );
        $this->assertStringContainsString(
            '<video controls preload="metadata" style="width: 100%; max-width: 320px;" src="'.$renderUrl.'"></video>',
            $html,
        );
        $this->assertStringContainsString(
            '<a href="'.$renderUrl.'" target="_blank" rel="noopener">Open in new tab</a>',
            $html,
        );
    }

    public function test_a_rendered_preview_link_actually_serves_the_file(): void
    {
        $this->actingAs(User::factory()->create());

        $disk = Storage::disk(config('filesystems.default'));
        $disk->put('videos/roundtrip.mp4', 'fake-video-bytes');

        try {
            $video = Video::factory()->create(['file_path' => 'videos/roundtrip.mp4']);

            $html = Livewire::test(ViewVideo::class, ['record' => $video->getRouteKey()])
                ->assertSuccessful()
                ->html();

            $this->assertSame(
                1,
                preg_match('#<video controls preload="metadata"[^>]*src="([^"]+)"></video>#', $html, $matches),
                'Expected an embedded <video> preview in the rendered view page.',
            );

            $this->get(html_entity_decode($matches[1]))->assertOk();
        } finally {
            $disk->delete('videos/roundtrip.mp4');
        }
    }
}

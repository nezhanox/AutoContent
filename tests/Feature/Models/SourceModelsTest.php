<?php

namespace Tests\Feature\Models;

use App\Models\Enums\SourceChannelFraming;
use App\Models\Enums\SourceChannelMode;
use App\Models\Enums\SourceVideoStatus;
use App\Models\SourceChannel;
use App\Models\SourceClip;
use App\Models\SourceVideo;
use App\Models\Video;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_links_channel_video_and_clip_with_enum_casts(): void
    {
        $channel = SourceChannel::factory()->create();
        $sourceVideo = SourceVideo::factory()->create(['source_channel_id' => $channel->id, 'transcript' => [['start' => 0, 'end' => 1, 'text' => 'hi']]]);
        $clip = SourceClip::factory()->create(['source_video_id' => $sourceVideo->id]);

        $this->assertSame(SourceChannelMode::Highlights, $channel->fresh()->mode);
        $this->assertSame(SourceChannelFraming::BlurPad, $channel->fresh()->framing);
        $this->assertTrue($channel->fresh()->is_active);
        $this->assertSame(SourceVideoStatus::Discovered, $sourceVideo->fresh()->status);
        $this->assertIsArray($sourceVideo->fresh()->transcript);
        $this->assertIsFloat($clip->fresh()->start);
        $this->assertTrue($channel->sourceVideos->first()->is($sourceVideo));
        $this->assertTrue($sourceVideo->sourceChannel->is($channel));
        $this->assertTrue($sourceVideo->clips->first()->is($clip));
        $this->assertTrue($clip->sourceVideo->is($sourceVideo));
        $this->assertTrue($channel->contentProject->is($channel->contentProject()->first()));
    }

    public function test_it_allows_a_video_without_idea_and_script_linked_to_a_source_clip(): void
    {
        $clip = SourceClip::factory()->create();

        $video = Video::factory()->create([
            'content_idea_id' => null,
            'script_id' => null,
            'source_clip_id' => $clip->id,
        ]);

        $this->assertTrue($video->fresh()->sourceClip->is($clip));
    }

    public function test_it_rejects_duplicate_youtube_id(): void
    {
        SourceVideo::factory()->create(['youtube_id' => 'abcdefghijk']);

        $this->expectException(QueryException::class);

        SourceVideo::factory()->create(['youtube_id' => 'abcdefghijk']);
    }
}

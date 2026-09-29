<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\SourceChannels\Pages\EditSourceChannel;
use App\Filament\Resources\SourceChannels\RelationManagers\SourceVideosRelationManager;
use App\Jobs\CreateClipVideosJob;
use App\Jobs\DownloadSourceVideoJob;
use App\Jobs\SelectClipsJob;
use App\Jobs\TranscribeSourceVideoJob;
use App\Models\Enums\SourceVideoStatus;
use App\Models\SourceChannel;
use App\Models\SourceVideo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SourceVideosRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        Queue::fake();
    }

    private function relationManager(SourceChannel $channel)
    {
        return Livewire::test(SourceVideosRelationManager::class, [
            'ownerRecord' => $channel,
            'pageClass' => EditSourceChannel::class,
        ]);
    }

    public function test_it_lists_the_channel_videos_including_failed_ones_with_error_text(): void
    {
        $channel = SourceChannel::factory()->create();
        $ok = SourceVideo::factory()->create(['source_channel_id' => $channel->id, 'status' => SourceVideoStatus::ClipsCreated]);
        $failed = SourceVideo::factory()->create([
            'source_channel_id' => $channel->id,
            'status' => SourceVideoStatus::Failed,
            'failed_stage' => 'download',
            'error_message' => 'yt-dlp exploded',
        ]);
        $other = SourceVideo::factory()->create();

        $this->relationManager($channel)
            ->assertCanSeeTableRecords([$ok, $failed])
            ->assertCanNotSeeTableRecords([$other])
            ->assertSee('yt-dlp exploded')
            ->assertSee('download');
    }

    public function test_it_filters_by_status(): void
    {
        $channel = SourceChannel::factory()->create();
        $ok = SourceVideo::factory()->create(['source_channel_id' => $channel->id, 'status' => SourceVideoStatus::ClipsCreated]);
        $failed = SourceVideo::factory()->create(['source_channel_id' => $channel->id, 'status' => SourceVideoStatus::Failed, 'failed_stage' => 'select']);

        $this->relationManager($channel)
            ->filterTable('status', 'failed')
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$ok]);
    }

    /**
     * @return array<string, array{string, SourceVideoStatus, class-string}>
     */
    public static function stages(): array
    {
        return [
            'download' => ['download', SourceVideoStatus::Discovered, DownloadSourceVideoJob::class],
            'transcribe' => ['transcribe', SourceVideoStatus::Downloaded, TranscribeSourceVideoJob::class],
            'select' => ['select', SourceVideoStatus::Transcribed, SelectClipsJob::class],
            'create' => ['create', SourceVideoStatus::ClipsSelected, CreateClipVideosJob::class],
        ];
    }

    #[DataProvider('stages')]
    public function test_retry_resets_status_and_dispatches_the_stage_job(string $stage, SourceVideoStatus $expected, string $jobClass): void
    {
        $channel = SourceChannel::factory()->create();
        $video = SourceVideo::factory()->create([
            'source_channel_id' => $channel->id,
            'status' => SourceVideoStatus::Failed,
            'failed_stage' => $stage,
            'error_message' => 'boom',
        ]);

        $this->relationManager($channel)
            ->callTableAction('retry', $video)
            ->assertHasNoTableActionErrors();

        $fresh = $video->fresh();
        $this->assertSame($expected, $fresh->status);
        $this->assertNull($fresh->failed_stage);
        $this->assertNull($fresh->error_message);
        Queue::assertPushed($jobClass, fn ($job) => $job->sourceVideoId === $video->id);
    }

    public function test_retry_with_an_unknown_stage_does_nothing(): void
    {
        $channel = SourceChannel::factory()->create();
        $video = SourceVideo::factory()->create([
            'source_channel_id' => $channel->id,
            'status' => SourceVideoStatus::Failed,
            'failed_stage' => 'mystery',
            'error_message' => 'boom',
        ]);

        $this->relationManager($channel)->callTableAction('retry', $video);

        $fresh = $video->fresh();
        $this->assertSame(SourceVideoStatus::Failed, $fresh->status);
        $this->assertSame('boom', $fresh->error_message);
        Queue::assertNothingPushed();
    }

    public function test_retry_is_hidden_for_non_failed_videos(): void
    {
        $channel = SourceChannel::factory()->create();
        $video = SourceVideo::factory()->create(['source_channel_id' => $channel->id, 'status' => SourceVideoStatus::Downloaded]);

        $this->relationManager($channel)->assertTableActionHidden('retry', $video);
    }
}

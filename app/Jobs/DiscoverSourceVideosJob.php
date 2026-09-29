<?php

namespace App\Jobs;

use App\Domain\Source\YoutubeDownloaderInterface;
use App\Models\Enums\SourceVideoStatus;
use App\Models\SourceChannel;
use App\Models\SourceVideo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class DiscoverSourceVideosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function __construct(public readonly int $channelId)
    {
        $this->onQueue('default');
    }

    public function handle(YoutubeDownloaderInterface $downloader): void
    {
        $channel = SourceChannel::findOrFail($this->channelId);

        if (! $channel->is_active) {
            return;
        }

        $listed = $downloader->listRecent($channel->url, (int) config('clips.discover_limit'));

        $ids = array_column($listed, 'id');
        $known = SourceVideo::whereIn('youtube_id', $ids)->pluck('youtube_id')->all();

        $created = [];
        foreach ($listed as $item) {
            if (in_array($item['id'], $known, true)) {
                continue;
            }
            $known[] = $item['id'];

            $created[] = SourceVideo::create([
                'source_channel_id' => $channel->id,
                'youtube_id' => $item['id'],
                'title' => $item['title'],
                'duration' => $item['duration'],
                'status' => SourceVideoStatus::Discovered,
            ]);
        }

        $channel->update([
            'name' => $channel->name ?? $downloader->channelName($channel->url),
            'last_checked_at' => now(),
        ]);

        foreach ($created as $sourceVideo) {
            DownloadSourceVideoJob::dispatch($sourceVideo->id);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('video')->error('Source video discovery failed', [
            'channel_id' => $this->channelId,
            'error' => $exception->getMessage(),
        ]);
    }
}

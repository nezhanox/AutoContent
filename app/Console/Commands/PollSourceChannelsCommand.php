<?php

namespace App\Console\Commands;

use App\Jobs\DiscoverSourceVideosJob;
use App\Models\SourceChannel;
use Illuminate\Console\Command;

class PollSourceChannelsCommand extends Command
{
    protected $signature = 'source:poll {--channel= : Poll only this source channel id}';

    protected $description = 'Dispatch discovery jobs for active source channels.';

    public function handle(): int
    {
        $channelOption = $this->option('channel');

        if ($channelOption !== null && ! preg_match('/\A[1-9][0-9]*\z/', (string) $channelOption)) {
            $this->error('The --channel option must be a positive integer.');

            return self::FAILURE;
        }

        $query = SourceChannel::query()->where('is_active', true);

        if ($channelOption !== null) {
            $query->whereKey((int) $channelOption);
        }

        $count = 0;
        foreach ($query->pluck('id') as $channelId) {
            DiscoverSourceVideosJob::dispatch($channelId);
            $count++;
        }

        if ($count === 0 && $channelOption !== null) {
            $this->warn("No active source channel matched --channel={$channelOption}.");
        }

        $this->info("Dispatched {$count} discovery job(s).");

        return self::SUCCESS;
    }
}

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
        $query = SourceChannel::query()->where('is_active', true);

        if ($this->option('channel') !== null) {
            $query->whereKey((int) $this->option('channel'));
        }

        $count = 0;
        foreach ($query->pluck('id') as $channelId) {
            DiscoverSourceVideosJob::dispatch($channelId);
            $count++;
        }

        $this->info("Dispatched {$count} discovery job(s).");

        return self::SUCCESS;
    }
}

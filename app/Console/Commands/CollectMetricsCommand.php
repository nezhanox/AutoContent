<?php

namespace App\Console\Commands;

use App\Jobs\CollectVideoMetricsJob;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use Illuminate\Console\Command;

class CollectMetricsCommand extends Command
{
    protected $signature = 'metrics:collect';

    protected $description = 'Dispatch CollectVideoMetricsJob for every published publication.';

    public function handle(): int
    {
        $published = Publication::where('status', PublicationStatus::Published)->get();

        foreach ($published as $publication) {
            CollectVideoMetricsJob::dispatch($publication->id);
        }

        $this->info("Dispatched {$published->count()} metrics collection job(s).");

        return self::SUCCESS;
    }
}

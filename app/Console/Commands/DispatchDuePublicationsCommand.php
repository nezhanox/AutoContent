<?php

namespace App\Console\Commands;

use App\Jobs\PublishVideoJob;
use App\Models\Enums\PublicationStatus;
use App\Models\Publication;
use Illuminate\Console\Command;

class DispatchDuePublicationsCommand extends Command
{
    protected $signature = 'publications:dispatch-due';

    protected $description = 'Dispatch PublishVideoJob for every publication whose scheduled_at is due.';

    public function handle(): int
    {
        $due = Publication::where('status', PublicationStatus::Scheduled)
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($due as $publication) {
            PublishVideoJob::dispatch($publication->id);
        }

        $this->info("Dispatched {$due->count()} publication(s).");

        return self::SUCCESS;
    }
}

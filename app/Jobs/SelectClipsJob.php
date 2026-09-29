<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

// Minimal stub; the full implementation lands in the clip-selection task.
class SelectClipsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly int $sourceVideoId)
    {
        $this->onQueue('default');
    }

    public function handle(): void {}
}

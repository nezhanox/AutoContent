<?php

namespace App\Jobs\Concerns;

use App\Models\User;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

trait NotifiesOnPermanentFailure
{
    /**
     * @param  array<string, mixed>  $context
     */
    protected function notifyPermanentFailure(string $logChannel, string $message, array $context): void
    {
        Log::channel($logChannel)->error($message, $context);

        Notification::send(User::all(), new PipelineJobFailedNotification($message, $context));
    }
}

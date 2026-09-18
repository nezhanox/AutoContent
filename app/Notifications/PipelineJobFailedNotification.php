<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class PipelineJobFailedNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $message,
        public readonly array $context,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}

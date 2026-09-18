<?php

namespace Tests\Unit\Jobs\Concerns;

use App\Jobs\Concerns\NotifiesOnPermanentFailure;
use App\Models\User;
use App\Notifications\PipelineJobFailedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotifiesOnPermanentFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_notifies_every_user_with_the_given_message_and_context(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $subject = new class
        {
            use NotifiesOnPermanentFailure;

            public function trigger(): void
            {
                $this->notifyPermanentFailure('video', 'Something failed permanently.', ['video_id' => 42]);
            }
        };

        $subject->trigger();

        Notification::assertSentTo(
            $user,
            PipelineJobFailedNotification::class,
            fn (PipelineJobFailedNotification $notification): bool => $notification->message === 'Something failed permanently.'
                && $notification->context === ['video_id' => 42]
        );
    }
}

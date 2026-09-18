<?php

namespace App\Domain\Publishing\Providers;

use App\Domain\Publishing\PublishResult;
use App\Domain\Publishing\SocialPublisherInterface;
use App\Models\Publication;
use Illuminate\Support\Str;

final class FakeSocialPublisher implements SocialPublisherInterface
{
    private ?PublishResult $result = null;

    public function respondWith(PublishResult $result): static
    {
        $this->result = $result;

        return $this;
    }

    public function publish(Publication $publication): PublishResult
    {
        return $this->result ?? new PublishResult(
            externalPostId: 'fake-'.Str::uuid(),
            metadata: ['platform' => $publication->socialAccount->platform->value],
        );
    }
}

<?php

namespace App\Domain\Publishing;

use App\Models\Publication;

interface SocialPublisherInterface
{
    public function publish(Publication $publication): PublishResult;
}

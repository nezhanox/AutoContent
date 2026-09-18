<?php

namespace App\Providers;

use App\Domain\Publishing\Providers\FakeSocialPublisher;
use App\Domain\Publishing\SocialPublisherInterface;
use Illuminate\Support\ServiceProvider;

class PublishingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SocialPublisherInterface::class, FakeSocialPublisher::class);
    }
}

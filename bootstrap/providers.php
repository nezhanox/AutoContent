<?php

use App\Providers\AppServiceProvider;
use App\Providers\AssetServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\LlmServiceProvider;
use App\Providers\TtsServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    HorizonServiceProvider::class,
    LlmServiceProvider::class,
    TtsServiceProvider::class,
    AssetServiceProvider::class,
];

<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\LlmServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    HorizonServiceProvider::class,
    LlmServiceProvider::class,
];

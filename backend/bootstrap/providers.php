<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\HttpClientServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    HttpClientServiceProvider::class,
];

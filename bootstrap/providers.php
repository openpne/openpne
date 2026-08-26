<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FilesServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\OutboundServiceProvider;
use App\Providers\WebPushServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    FilesServiceProvider::class,
    FortifyServiceProvider::class,
    OutboundServiceProvider::class,
    WebPushServiceProvider::class,
];

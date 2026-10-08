<?php

use App\Products\MailForge\Providers\MailForgeServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\TelescopeServiceProvider;

return [
    MailForgeServiceProvider::class,
    AppServiceProvider::class,
    TelescopeServiceProvider::class,
];

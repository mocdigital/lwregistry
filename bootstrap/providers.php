<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\TranslationServiceProvider;
use App\Providers\VoltServiceProvider;

return [
    AppServiceProvider::class,
    TranslationServiceProvider::class,
    VoltServiceProvider::class,
];

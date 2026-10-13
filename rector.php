<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/app',
        __DIR__ . '/bootstrap',
        __DIR__ . '/config',
        __DIR__ . '/database',
        __DIR__ . '/public',
        __DIR__ . '/resources',
        __DIR__ . '/routes',
        __DIR__ . '/tests',
    ])
    ->withSets([
        LaravelSetList::LARAVEL_120,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
        SetList::PHP_84,
        SetList::CODE_QUALITY,
        SetList::EARLY_RETURN,
    ])
    ->withPhpSets(php84: true)
    ->withImportNames()
    ->withTypeCoverageLevel(3)
    ->withDeadCodeLevel(2)
    ->withSkip([
        __DIR__ . '/bootstrap/cache',
        __DIR__ . '/vendor',
    ]);

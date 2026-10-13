<?php

declare(strict_types=1);

if ( ! function_exists('activity_badge')) {
    function activity_badge(string $status): string
    {
        return match ($status) {
            'pending' => 'badge-warning-soft',
            'verified' => 'badge-primary-soft',
            'updated' => 'badge-primary-soft',
            'registered' => 'badge-success-soft',
            'sent' => 'badge-success-soft',
            'flagged' => 'badge-danger-soft',
            default => 'badge-secondary-soft',
        };
    }
}

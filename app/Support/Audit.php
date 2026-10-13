<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Support\Str;

/**
 * Lightweight helper to record admin actions to the activity log consistently.
 */
final class Audit
{
    /**
     * @param  string  $type  Machine type, e.g. 'category_created'.
     * @param  string  $title  Human title (usually the affected record's name).
     * @param  string  $action  Past-tense verb phrase, e.g. 'Category created'.
     * @param  string  $status  Short status label stored on the log.
     */
    public static function log(string $type, string $title, string $action, string $status = 'updated'): void
    {
        $actor = optional(auth()->user())->name ?? 'system';

        ActivityLog::create([
            'type' => $type,
            'title' => $title,
            'description' => $action . ' by <strong>' . $actor . '</strong>.',
            'status' => $status,
            'icon' => Str::upper(Str::substr($title, 0, 2)),
            'user_id' => auth()->id(),
        ]);
    }
}

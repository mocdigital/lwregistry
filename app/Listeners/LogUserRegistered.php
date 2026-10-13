<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Events\Registered;

final class LogUserRegistered
{
    /**
     * Handle the event.
     */
    public function handle(Registered $event): void
    {
        $user = $event->user;

        if ( ! $user instanceof User) {
            return;
        }

        ActivityLog::create([
            'type' => 'user_registered',
            'title' => $user->name ?? $user->email,
            'description' => 'New user registered with email <strong>' . e($user->email) . '</strong>.',
            'status' => 'registered',
            'icon' => mb_strtoupper(mb_substr($user->name ?? 'U', 0, 2)),
            'user_id' => $user->id,
        ]);
    }
}

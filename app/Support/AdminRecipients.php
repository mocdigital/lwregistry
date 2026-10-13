<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

final class AdminRecipients
{
    /**
     * All admin/super-admin users who should receive admin alerts.
     *
     * @return Collection<int, User>
     */
    public static function users(): Collection
    {
        return User::query()
            ->whereIn('role', [UserRole::ADMIN->value, UserRole::SUPER_ADMIN->value])
            ->get();
    }

    /**
     * Super admins who should receive payment-success escalation alerts.
     *
     * @return Collection<int, User>
     */
    public static function superAdmins(): Collection
    {
        return User::query()
            ->where('role', UserRole::SUPER_ADMIN->value)
            ->get();
    }

    /**
     * Send a notification to all admin users. Falls back to the configured
     * super admin email address when no admin users exist yet.
     */
    public static function notify(object $notification): void
    {
        $users = self::users();

        if ($users->isNotEmpty()) {
            Notification::send($users, $notification);

            return;
        }

        $fallback = config('app.super_admin_email');

        if (is_string($fallback) && '' !== $fallback) {
            (new AnonymousNotifiable())
                ->route('mail', $fallback)
                ->notify($notification);
        }
    }

    /**
     * Send a notification only to super admins, with the configured super-admin
     * mailbox as a fallback when no super-admin user record exists yet.
     */
    public static function notifySuperAdmins(object $notification): void
    {
        $users = self::superAdmins();

        if ($users->isNotEmpty()) {
            Notification::send($users, $notification);

            return;
        }

        $fallback = config('app.super_admin_email');

        if (is_string($fallback) && '' !== $fallback) {
            (new AnonymousNotifiable())
                ->route('mail', $fallback)
                ->notify($notification);
        }
    }
}

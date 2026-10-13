<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\Sbo;
use App\Models\User;
use App\Notifications\SboManagerAssignedNotification;
use Illuminate\Support\Str;

final class SboManagerAssigner
{
    /**
     * Assign (or reassign) the single manager of an SBO.
     *
     * - Repoints Sbo::created_by to the new user.
     * - Promotes the user to SBO_MANAGER, unless they already hold a higher
     *   (admin / super admin) role, which is left untouched.
     * - Notifies the new manager and logs the change.
     */
    public static function assign(Sbo $sbo, User $newManager, ?User $actor = null): void
    {
        $previousManagerId = $sbo->created_by;

        // Don't churn if nothing changes.
        if ($previousManagerId === $newManager->id) {
            return;
        }

        $sbo->update(['created_by' => $newManager->id]);

        // Promote to SBO manager only if they aren't already an admin/super admin.
        if ( ! $newManager->role->isAdmin()) {
            $newManager->update(['role' => UserRole::SBO_MANAGER]);
        }

        $newManager->notify(new SboManagerAssignedNotification($sbo));

        ActivityLog::create([
            'type' => 'sbo_manager_assigned',
            'title' => $sbo->name,
            'description' => 'Manager set to <strong>' . $newManager->name . '</strong> by <strong>'
                . (optional($actor)->name ?? 'admin') . '</strong>.',
            'status' => 'updated',
            'icon' => Str::upper(Str::substr($sbo->name, 0, 2)),
            'user_id' => optional($actor)->id,
        ]);
    }
}

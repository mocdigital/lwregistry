<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Sbo;
use App\Models\User;
use App\Notifications\SboVerifiedNotification;
use Illuminate\Support\Str;

/**
 * Single choke point for "an SBO has become fully verified". Fully verified is
 * derived (approved + documents compliant + fees paid); this issues the one-time
 * artifacts — MOC registration number and the verified notification — exactly
 * once, whichever gate completes last (fees, documents, or approval).
 */
final class SboVerificationFinalizer
{
    /**
     * Issue the verified artifacts if (and only if) the SBO is now fully
     * verified and has not been notified before. Idempotent and safe to call
     * from any path that could complete the final gate.
     */
    public function finalizeIfReady(Sbo $sbo, ?User $reviewer = null): void
    {
        if ( ! $sbo->isFullyVerified()) {
            return;
        }

        $sbo->ensureMocRegistrationNumber();
        $sbo->refresh();

        app(SboSealIssuer::class)->issueFor($sbo);
        $sbo->load('seal');

        if (null !== $sbo->verified_notified_at) {
            return;
        }

        $sbo->update(['verified_notified_at' => now()]);
        $sbo->refresh();
        $sbo->load('seal');

        $sbo->creator?->notify(new SboVerifiedNotification($sbo));

        ActivityLog::create([
            'type' => 'sbo_verified',
            'title' => $sbo->name,
            'description' => '<strong>' . $sbo->name . '</strong> is now fully verified (approved, documents compliant, fees paid).',
            'status' => 'approved',
            'icon' => Str::upper(Str::substr($sbo->name, 0, 2)),
            'user_id' => $reviewer?->id,
        ]);
    }
}

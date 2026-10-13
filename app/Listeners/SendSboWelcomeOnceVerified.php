<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Notifications\SboRegistrationReceivedNotification;
use Illuminate\Auth\Events\Verified;

/**
 * Send the owner's welcome / next-steps email once their address is verified.
 *
 * The email itself says "your email has been verified" and points to the
 * registration-fee step, so it only makes sense after verification — and
 * holding it until here guarantees a member never receives it before the
 * "verify your email" message. Registration sends verification only; this sends
 * the welcome, one per listing the owner registered.
 */
final class SendSboWelcomeOnceVerified
{
    public function handle(Verified $event): void
    {
        $user = $event->user;

        if ( ! $user instanceof User) {
            return;
        }

        $user->sbos()->each(function ($sbo) use ($user): void {
            $user->notify(new SboRegistrationReceivedNotification($sbo));
        });
    }
}

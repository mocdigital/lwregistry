<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Sbo;
use App\Models\User;

final class SboWorkspaceAccess
{
    public function isSboManager(User $user): bool
    {
        return $user->role->isSboManager();
    }

    public function hasOwnerWorkspace(User $user): bool
    {
        return $user->sbos()->exists();
    }

    /**
     * Membership is a paid registration: at least one SBO of this user's whose
     * fee has been confirmed.
     *
     * This used to return true for a user who owned no SBO at all — "nothing
     * to pay for, so nothing owed" — which meant anyone without a listing
     * roamed the whole platform for free. The registry is paid to use, so
     * owning nothing is not a way past the gate; it is the state *before* the
     * gate.
     */
    public function hasApprovedPayment(User $user): bool
    {
        return $user->sbos()
            ->where('payment_status', 'paid')
            ->whereNotNull('payment_confirmed_at')
            ->exists();
    }

    public function requiresPayment(User $user): bool
    {
        /*
         * Admins run the registry rather than belong to it, and gating them
         * would lock the people who approve payments out of the screens where
         * they approve them.
         */
        if ($user->role?->isAdmin()) {
            return false;
        }

        return ! $this->hasApprovedPayment($user);
    }

    /**
     * Whether the member must still be ADMIN-APPROVED before the restricted
     * platform pages open. Access is granted the moment an admin APPROVES the
     * business (verification_status = approved). Documents-compliance and
     * payment confirmation are separate, later gates and are NOT required for
     * general platform access — a member who is approved but still settling
     * documents or fees can browse the registry. Paying alone is still not
     * enough; approval is the key. Admins are never gated.
     *
     * Note the SBO Network feature keeps its own, stricter requirement of full
     * verification (see SboNetworkAccess); this gate is only for the general
     * platform.
     */
    public function requiresVerification(User $user): bool
    {
        if ($user->role?->isAdmin()) {
            return false;
        }

        return ! $this->hasApprovedBusiness($user);
    }

    /**
     * At least one of the member's businesses has been admin-approved and is
     * live — approved verification status, active, and the owner in good
     * standing (not suspended). This is what opens the general platform.
     */
    public function hasApprovedBusiness(User $user): bool
    {
        if ($this->ownerIsSuspended($user)) {
            return false;
        }

        return $user->sbos()
            ->where('verification_status', 'approved')
            ->where('is_active', true)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Gated, and with no application to pay for — so the way in is to register
     * a business, not to open a payment screen that has no fee on it.
     */
    public function needsRegistration(User $user): bool
    {
        return $this->requiresPayment($user) && ! $this->hasOwnerWorkspace($user);
    }

    public function paymentTarget(User $user): ?Sbo
    {
        if ( ! $this->hasOwnerWorkspace($user)) {
            return null;
        }

        return $user->sbos()
            ->where(function ($query): void {
                $query->where('payment_status', '!=', 'paid')
                    ->orWhereNull('payment_confirmed_at');
            })
            ->oldest()
            ->first()
            ?? $user->sbos()->oldest()->first();
    }

    private function ownerIsSuspended(User $user): bool
    {
        if (array_key_exists('suspended_at', $user->getAttributes())) {
            return null !== $user->suspended_at;
        }

        return $user->newQuery()
            ->whereKey($user->id)
            ->whereNotNull('suspended_at')
            ->exists();
    }
}

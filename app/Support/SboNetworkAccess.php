<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Collection;

final class SboNetworkAccess
{
    /**
     * @return Collection<int, Sbo>
     */
    public function verifiedSbosFor(User $user): Collection
    {
        if ($this->userIsSuspended($user)) {
            return collect();
        }

        return $user->sbos()
            ->orderBy('name')
            ->get()
            ->filter(fn (Sbo $sbo): bool => $this->sboCanUseNetwork($sbo))
            ->values();
    }

    /**
     * @return array<int, int>
     */
    public function verifiedSboIdsFor(User $user): array
    {
        return $this->verifiedSbosFor($user)
            ->pluck('id')
            ->all();
    }

    public function userCanUseNetwork(User $user): bool
    {
        return $this->verifiedSbosFor($user)->isNotEmpty();
    }

    public function sboCanUseNetwork(Sbo $sbo): bool
    {
        return $sbo->isFullyVerified()
            && $sbo->is_active
            && 'active' === $sbo->status
            && ! $sbo->isDeclined()
            && ! $this->sboOwnerIsSuspended($sbo);
    }

    public function userOwnsSbo(User $user, Sbo $sbo): bool
    {
        return (int) $sbo->created_by === (int) $user->id;
    }

    private function userIsSuspended(User $user): bool
    {
        if (array_key_exists('suspended_at', $user->getAttributes())) {
            return null !== $user->suspended_at;
        }

        return $user->newQuery()
            ->whereKey($user->id)
            ->whereNotNull('suspended_at')
            ->exists();
    }

    private function sboOwnerIsSuspended(Sbo $sbo): bool
    {
        if ( ! $sbo->created_by) {
            return false;
        }

        return User::query()
            ->whereKey($sbo->created_by)
            ->whereNotNull('suspended_at')
            ->exists();
    }
}

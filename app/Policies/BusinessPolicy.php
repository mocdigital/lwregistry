<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Business;
use App\Models\User;

final class BusinessPolicy
{
    /**
     * Determine if the user can view any businesses.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * Determine if the user can view the business.
     */
    public function view(?User $user, Business $business): bool
    {
        return true;
    }

    /**
     * Determine if the user can create businesses.
     */
    public function create(?User $user): bool
    {
        return null !== $user;
    }

    /**
     * Determine if the user can update the business.
     */
    public function update(?User $user, Business $business): bool
    {
        return null !== $user && $user->id === $business->user_id;
    }

    /**
     * Determine if the user can delete the business.
     */
    public function delete(?User $user, Business $business): bool
    {
        return null !== $user && $user->id === $business->user_id;
    }

    /**
     * Determine if the user can verify the business.
     */
    public function verify(?User $user, Business $business): bool
    {
        return null !== $user && $user->isAdmin();
    }
}

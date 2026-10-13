<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\AdminWelcomeNotification;
use Illuminate\Support\Str;

final class UserObserver
{
    /**
     * Temporary plaintext passwords generated for SBO Managers during creation,
     * kept in memory only (keyed by user object hash) so the welcome email can
     * display them. The observer instance is shared, so we avoid a single scalar.
     *
     * @var array<string, string>
     */
    private array $generatedPasswords = [];

    public function creating(User $user): void
    {
        // Auto-assign super admin role if email matches config
        if ($user->email === config('app.super_admin_email')) {
            $user->role = UserRole::SUPER_ADMIN;
        }

        // For SBO Managers created without a password, generate a temporary one.
        // The model casts `password` => 'hashed', so assigning the plaintext here
        // lets the cast hash it on save; we keep the plaintext for the email.
        if (UserRole::SBO_MANAGER === $user->role && empty($user->getAttributes()['password'] ?? null)) {
            $password = Str::password(12);
            $this->generatedPasswords[spl_object_hash($user)] = $password;
            $user->password = $password;
        }
    }

    public function created(User $user): void
    {
        $key = spl_object_hash($user);

        // Send login credentials to a newly created SBO Manager.
        if (UserRole::SBO_MANAGER === $user->role && isset($this->generatedPasswords[$key])) {
            $user->notify(new AdminWelcomeNotification($this->generatedPasswords[$key]));
            unset($this->generatedPasswords[$key]);
        }
    }
}

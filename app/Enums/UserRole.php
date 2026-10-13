<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case USER = 'user';
    case SBO_MANAGER = 'sbo_manager';
    case ADMIN = 'admin';
    case SUPER_ADMIN = 'super_admin';

    public function label(): string
    {
        return match ($this) {
            self::USER => 'User',
            self::SBO_MANAGER => 'SBO Manager',
            self::ADMIN => 'Admin',
            self::SUPER_ADMIN => 'Super Admin',
        };
    }

    public function isAdmin(): bool
    {
        return self::ADMIN === $this || self::SUPER_ADMIN === $this;
    }

    public function isSuperAdmin(): bool
    {
        return self::SUPER_ADMIN === $this;
    }

    public function isSboManager(): bool
    {
        return self::SBO_MANAGER === $this;
    }
}

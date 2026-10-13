<?php

declare(strict_types=1);

namespace App\Enums;

enum InventoryOrderStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending Payment',
            self::PAID => 'Paid',
            self::CANCELLED => 'Cancelled',
        };
    }

    /**
     * Bootstrap badge classes matching the app's existing subtle-badge style.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'bg-warning-subtle text-warning',
            self::PAID => 'bg-success-subtle text-success',
            self::CANCELLED => 'bg-danger-subtle text-danger',
        };
    }

    public function isPaid(): bool
    {
        return self::PAID === $this;
    }

    public function isPending(): bool
    {
        return self::PENDING === $this;
    }

    public function isCancelled(): bool
    {
        return self::CANCELLED === $this;
    }
}

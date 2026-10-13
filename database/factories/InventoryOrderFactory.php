<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InventoryOrderStatus;
use App\Models\Business;
use App\Models\InventoryOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryOrder>
 */
final class InventoryOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Central admin-owned store by default (no business).
            'business_id' => null,
            'created_by' => null,
            'customer_name' => fake()->optional()->name(),
            'customer_phone' => fake()->optional()->phoneNumber(),
            'customer_email' => fake()->optional()->safeEmail(),
            'price_type' => fake()->randomElement(['retail', 'bulk']),
            'status' => InventoryOrderStatus::PENDING,
            'total_amount' => 0,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function paid(): self
    {
        return $this->state(fn () => [
            'status' => InventoryOrderStatus::PAID,
            'paid_at' => now(),
        ]);
    }
}

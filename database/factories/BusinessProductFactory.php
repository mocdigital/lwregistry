<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\BusinessProduct;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BusinessProduct>
 */
final class BusinessProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $retail = fake()->numberBetween(500, 50000);

        return [
            // Central admin-owned store by default (no business).
            'business_id' => null,
            'name' => fake()->words(3, true),
            'code' => mb_strtoupper(Str::random(6)),
            'category' => fake()->optional()->word(),
            'size' => fake()->optional()->randomElement(['S', 'M', 'L', 'XL']),
            'colour' => fake()->optional()->safeColorName(),
            'sku' => mb_strtoupper(Str::random(8)),
            'description' => fake()->optional()->sentence(),
            'retail_price' => $retail,
            'bulk_price' => (int) round($retail * 0.8),
            'bulk_min_quantity' => fake()->numberBetween(5, 20),
            'stock_quantity' => fake()->numberBetween(0, 200),
            'low_stock_threshold' => fake()->numberBetween(0, 10),
            'is_active' => true,
        ];
    }

    public function outOfStock(): self
    {
        return $this->state(fn () => ['stock_quantity' => 0]);
    }

    public function withStock(int $quantity): self
    {
        return $this->state(fn () => ['stock_quantity' => $quantity]);
    }

    public function forBusiness(): self
    {
        return $this->state(fn () => ['business_id' => Business::factory()]);
    }
}

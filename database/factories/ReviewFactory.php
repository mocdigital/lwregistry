<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
final class ReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'user_id' => User::factory(),
            'rating' => fake()->numberBetween(1, 5),
            'comment' => fake()->optional(0.8)->paragraph(),
        ];
    }

    /**
     * Indicate a high rating (4-5 stars).
     */
    public function highRating(): static
    {
        return $this->state(fn (array $attributes): array => [
            'rating' => fake()->numberBetween(4, 5),
        ]);
    }

    /**
     * Indicate a low rating (1-2 stars).
     */
    public function lowRating(): static
    {
        return $this->state(fn (array $attributes): array => [
            'rating' => fake()->numberBetween(1, 2),
        ]);
    }
}

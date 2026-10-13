<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Business;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Business>
 */
final class BusinessFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory(),
            'name' => $name,
            'slug' => Str::slug($name . '-' . fake()->randomNumber(4)),
            'description' => fake()->optional()->paragraph(),
            'phone' => null,
            'email' => fake()->optional()->companyEmail(),
            'website' => fake()->optional()->url(),
            'address' => fake()->optional()->streetAddress(),
            'city' => fake()->city(),
            'state_id' => null,
            'country' => fake()->country(),
            'latitude' => fake()->optional()->latitude(),
            'longitude' => fake()->optional()->longitude(),
            'is_verified' => fake()->boolean(70),
            'verified_at' => fake()->optional(0.7)->dateTimeBetween('-1 year'),
            'is_featured' => fake()->boolean(10),
            'is_24_7' => fake()->boolean(20),
            'status' => fake()->randomElement(['active', 'pending', 'suspended']),
            'staff_size' => fake()->optional()->randomElement(['1-10', '11-50', '51-200', '201-500', '500+']),
            'years_active' => fake()->optional()->numberBetween(1, 50),
            'avg_rating' => fake()->randomFloat(2, 0, 5),
            'total_reviews' => fake()->numberBetween(0, 500),
            'views_count' => fake()->numberBetween(0, 10000),
            'saves_count' => fake()->numberBetween(0, 500),
            'favorites_count' => fake()->numberBetween(0, 500),
        ];
    }

    /**
     * Indicate that the business is verified.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => true,
            'verified_at' => now(),
        ]);
    }

    /**
     * Indicate that the business is unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_verified' => false,
            'verified_at' => null,
        ]);
    }
}

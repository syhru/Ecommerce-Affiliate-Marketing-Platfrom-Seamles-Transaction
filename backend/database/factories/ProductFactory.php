<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'brand' => fake()->company(),
            'type' => fake()->randomElement(['pump', 'shockbreaker', 'hose']),
            'category' => fake()->randomElement(['motor', 'shockbreaker']),
            'description' => fake()->sentence(),
            'price' => fake()->numberBetween(10000, 500000),
            'stock' => fake()->numberBetween(0, 50),
            'is_active' => true,
        ];
    }

    /**
     * Product with a fixed stock level.
     */
    public function stock(int $stock): static
    {
        return $this->state(fn (array $attributes) => ['stock' => $stock]);
    }
}

<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{

    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name'          => fake()->name(),
            'email'         => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role'          => 'customer',
            'is_active'     => true,
            'credential_version' => 1,
            'remember_token' => Str::random(10),
        ];
    }

    public function superadmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'superadmin',
        ]);
    }

    public function admin(): static
    {
        return $this->superadmin();
    }

    public function affiliate(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'affiliate',
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'discord_id' => (string) fake()->unique()->numerify('##################'),
            'avatar' => null,
            'character_name' => fake()->name(),
            'role' => Role::Gang,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['disabled_at' => now()]);
    }

    public function loggedIn(): static
    {
        return $this->state(fn () => ['last_login_at' => now()->subDay()]);
    }

    public function role(Role $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\GroupStatus;
use App\Enums\GroupType;
use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => GroupType::Gang,
            'name' => strtoupper(fake()->unique()->lexify('????')).' '.fake()->unique()->numerify('###'),
            'description' => null,
            'logo_url' => null,
            'status' => GroupStatus::Normal,
            'registered_at' => now()->subDays(fake()->numberBetween(1, 120)),
        ];
    }

    public function family(): static
    {
        return $this->state(fn () => ['type' => GroupType::Family]);
    }

    public function status(GroupStatus $status, ?string $reason = null): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'status_reason' => $reason,
            'status_changed_at' => now(),
        ]);
    }
}

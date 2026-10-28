<?php

namespace Database\Factories;

use App\Enums\MemberPosition;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupMember>
 */
class GroupMemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'user_id' => null,
            'character_name' => fake()->firstName().' '.fake()->lastName(),
            'position' => MemberPosition::Member,
            'joined_at' => now()->subDays(fake()->numberBetween(1, 90)),
            'left_at' => null,
        ];
    }

    public function position(MemberPosition $position): static
    {
        return $this->state(fn () => ['position' => $position]);
    }

    public function left(): static
    {
        return $this->state(fn () => ['left_at' => now()->subDay()]);
    }
}

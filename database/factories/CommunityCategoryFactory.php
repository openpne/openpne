<?php

namespace Database\Factories;

use App\Models\CommunityCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunityCategory>
 */
class CommunityCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'is_allow_member_community' => true,
            'sort_order' => fake()->numberBetween(1, 100),
            'parent_id' => null,
        ];
    }

    public function adminOnly(): static
    {
        return $this->state(['is_allow_member_community' => false]);
    }
}

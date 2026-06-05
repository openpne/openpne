<?php

namespace Database\Factories;

use App\Features\Community\JoinPolicy;
use App\Models\Community;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Community>
 */
class CommunityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'description' => fake()->paragraph(),
            'register_policy' => JoinPolicy::Open,
            'community_category_id' => null,
            'file_id' => null,
        ];
    }

    public function approval(): static
    {
        return $this->state(['register_policy' => JoinPolicy::Approval]);
    }
}

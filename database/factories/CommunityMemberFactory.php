<?php

namespace Database\Factories;

use App\Features\Community\CommunityRole;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunityMember>
 */
class CommunityMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'community_id' => Community::factory(),
            'member_id' => Member::factory(),
            'role' => CommunityRole::Member,
            'is_pre' => false,
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => CommunityRole::Admin]);
    }

    public function subAdmin(): static
    {
        return $this->state(['role' => CommunityRole::SubAdmin]);
    }

    public function pending(): static
    {
        return $this->state(['is_pre' => true]);
    }
}

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
}

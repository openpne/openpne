<?php

namespace Database\Factories;

use App\Features\Group\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupMember>
 */
class GroupMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'member_id' => Member::factory(),
            'role' => GroupRole::Member,
        ];
    }

    public function member(): static
    {
        return $this->state(['role' => GroupRole::Member]);
    }

    public function admin(): static
    {
        return $this->state(['role' => GroupRole::Admin]);
    }

    public function subAdmin(): static
    {
        return $this->state(['role' => GroupRole::SubAdmin]);
    }
}

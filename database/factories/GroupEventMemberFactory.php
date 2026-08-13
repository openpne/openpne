<?php

namespace Database\Factories;

use App\Models\GroupEvent;
use App\Models\GroupEventMember;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupEventMember>
 */
class GroupEventMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'group_event_id' => GroupEvent::factory(),
            'member_id' => Member::factory(),
        ];
    }
}

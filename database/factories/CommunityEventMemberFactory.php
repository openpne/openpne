<?php

namespace Database\Factories;

use App\Models\CommunityEvent;
use App\Models\CommunityEventMember;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunityEventMember>
 */
class CommunityEventMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'community_event_id' => CommunityEvent::factory(),
            'member_id' => Member::factory(),
        ];
    }
}

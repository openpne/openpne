<?php

namespace Database\Factories;

use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunityEventComment>
 */
class CommunityEventCommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'community_event_id' => CommunityEvent::factory(),
            'member_id' => Member::factory(),
            'number' => 1,
            'body' => fake()->paragraph(),
        ];
    }
}

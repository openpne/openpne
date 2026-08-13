<?php

namespace Database\Factories;

use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupEventComment>
 */
class GroupEventCommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'group_event_id' => GroupEvent::factory(),
            'member_id' => Member::factory(),
            'number' => 1,
            'body' => fake()->paragraph(),
        ];
    }
}

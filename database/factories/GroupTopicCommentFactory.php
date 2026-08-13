<?php

namespace Database\Factories;

use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupTopicComment>
 */
class GroupTopicCommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'group_topic_id' => GroupTopic::factory(),
            'member_id' => Member::factory(),
            'number' => 1,
            'body' => fake()->paragraph(),
        ];
    }
}

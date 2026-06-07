<?php

namespace Database\Factories;

use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunityTopicComment>
 */
class CommunityTopicCommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'community_topic_id' => CommunityTopic::factory(),
            'member_id' => Member::factory(),
            'number' => 1,
            'body' => fake()->paragraph(),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Community;
use App\Models\CommunityTopic;
use App\Models\Member;
use App\Support\BodyFormat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunityTopic>
 */
class CommunityTopicFactory extends Factory
{
    public function definition(): array
    {
        return [
            'community_id' => Community::factory(),
            'member_id' => Member::factory(),
            'name' => fake()->sentence(4),
            'body' => fake()->paragraphs(2, true),
            'topic_updated_at' => null,
            // A make()d model carries no DB default, so pin the format the serializers read.
            'format' => BodyFormat::Plain,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Support\BodyFormat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupTopic>
 */
class GroupTopicFactory extends Factory
{
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'member_id' => Member::factory(),
            'name' => fake()->sentence(4),
            'body' => fake()->paragraphs(2, true),
            'topic_updated_at' => null,
            // A make()d model carries no DB default, so pin the format the serializers read.
            'format' => BodyFormat::Plain,
        ];
    }
}

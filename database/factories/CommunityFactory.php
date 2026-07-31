<?php

namespace Database\Factories;

use App\Features\Community\JoinPolicy;
use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Models\Community;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Community>
 */
class CommunityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'description' => fake()->paragraph(),
            'register_policy' => JoinPolicy::Open,
            'is_join_notification_enabled' => true,
            // The migration defaults (OpenPNE 3's config defaults), set here too so a freshly made
            // in-memory model carries them without a refresh().
            'topic_read_access' => TopicReadAccess::Everyone,
            'topic_post_authority' => TopicPostAuthority::Members,
            'community_category_id' => null,
            'file_id' => null,
        ];
    }

    public function approval(): static
    {
        return $this->state(['register_policy' => JoinPolicy::Approval]);
    }
}

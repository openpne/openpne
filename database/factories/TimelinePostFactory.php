<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimelinePost>
 */
class TimelinePostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'in_reply_to_id' => null,
            'body' => fake()->sentence(8),
            'visibility' => Visibility::Members,
        ];
    }

    public function private(): static
    {
        return $this->state(['visibility' => Visibility::Private]);
    }

    public function friends(): static
    {
        return $this->state(['visibility' => Visibility::Friends]);
    }

    /** Scoped to a community's timeline, which fixes visibility at Members (the runtime contract). */
    public function inGroup(Group $group): static
    {
        return $this->state([
            'community_id' => $group->getKey(),
            'visibility' => Visibility::Members,
        ]);
    }

    /** A reply to $parent, copying its visibility and community (the runtime contract). */
    public function replyTo(TimelinePost $parent): static
    {
        return $this->state([
            'in_reply_to_id' => $parent->getKey(),
            'visibility' => $parent->visibility,
            'community_id' => $parent->community_id,
        ]);
    }
}

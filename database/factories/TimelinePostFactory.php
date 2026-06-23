<?php

namespace Database\Factories;

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

    /** A reply to $parent, copying its visibility (the runtime contract). */
    public function replyTo(TimelinePost $parent): static
    {
        return $this->state([
            'in_reply_to_id' => $parent->getKey(),
            'visibility' => $parent->visibility,
        ]);
    }
}

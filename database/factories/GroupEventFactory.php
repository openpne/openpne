<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\Member;
use App\Support\BodyFormat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupEvent>
 */
class GroupEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'member_id' => Member::factory(),
            'name' => fake()->sentence(4),
            'body' => fake()->paragraphs(2, true),
            'event_updated_at' => null,
            // A week out so the event is open for RSVP by default; date-only.
            'open_date' => now()->addWeek()->startOfDay(),
            'open_date_comment' => '13:00-15:00',
            'area' => fake()->city(),
            'application_deadline' => null,
            'capacity' => null,
            // A make()d model carries no DB default, so pin the format the serializers read.
            'format' => BodyFormat::Plain,
        ];
    }
}

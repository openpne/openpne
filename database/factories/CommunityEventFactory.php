<?php

namespace Database\Factories;

use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunityEvent>
 */
class CommunityEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'community_id' => Community::factory(),
            'member_id' => Member::factory(),
            'name' => fake()->sentence(4),
            'body' => fake()->paragraphs(2, true),
            'event_updated_at' => null,
            // A week out so the event is open for RSVP by default; date-only, like OpenPNE 3.
            'open_date' => now()->addWeek()->startOfDay(),
            'open_date_comment' => '13:00-15:00',
            'area' => fake()->city(),
            'application_deadline' => null,
            'capacity' => null,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sender_id' => Member::factory(),
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraphs(2, true),
            'is_draft' => false,
        ];
    }

    public function draft(): static
    {
        return $this->state(['is_draft' => true]);
    }

    public function trashedBySender(): static
    {
        return $this->state(['sender_deleted_at' => now()]);
    }

    public function purgedBySender(): static
    {
        return $this->state(['sender_deleted_at' => now(), 'sender_purged_at' => now()]);
    }
}

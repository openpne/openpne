<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageRecipient>
 */
class MessageRecipientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'recipient_id' => Member::factory(),
            'read_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(['read_at' => now()]);
    }

    public function trashedByRecipient(): static
    {
        return $this->state(['recipient_deleted_at' => now()]);
    }

    public function purgedByRecipient(): static
    {
        return $this->state(['recipient_deleted_at' => now(), 'recipient_purged_at' => now()]);
    }
}

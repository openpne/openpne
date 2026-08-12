<?php

namespace Database\Factories;

use App\Models\DirectMessage;
use App\Models\DirectMessageRecipient;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DirectMessageRecipient>
 */
class DirectMessageRecipientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'direct_message_id' => DirectMessage::factory(),
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

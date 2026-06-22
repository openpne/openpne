<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\Message;
use App\Models\MessageFile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageFile>
 */
class MessageFileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'file_id' => File::factory(),
            'number' => 1,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\DirectMessage;
use App\Models\DirectMessageFile;
use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DirectMessageFile>
 */
class DirectMessageFileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'direct_message_id' => DirectMessage::factory(),
            'file_id' => File::factory(),
            'number' => 1,
        ];
    }
}

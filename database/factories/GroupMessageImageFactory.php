<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\GroupMessage;
use App\Models\GroupMessageImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupMessageImage>
 */
class GroupMessageImageFactory extends Factory
{
    protected $model = GroupMessageImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_message_id' => GroupMessage::factory(),
            'file_id' => File::factory(),
            'number' => 1,
        ];
    }
}

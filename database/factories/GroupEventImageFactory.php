<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\GroupEvent;
use App\Models\GroupEventImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupEventImage>
 */
class GroupEventImageFactory extends Factory
{
    protected $model = GroupEventImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => GroupEvent::factory(),
            'file_id' => File::factory(),
            'number' => 1,
        ];
    }
}

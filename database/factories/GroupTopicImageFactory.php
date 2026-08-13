<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\GroupTopic;
use App\Models\GroupTopicImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupTopicImage>
 */
class GroupTopicImageFactory extends Factory
{
    protected $model = GroupTopicImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => GroupTopic::factory(),
            'file_id' => File::factory(),
            'number' => 1,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\CommunityTopic;
use App\Models\CommunityTopicImage;
use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunityTopicImage>
 */
class CommunityTopicImageFactory extends Factory
{
    protected $model = CommunityTopicImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => CommunityTopic::factory(),
            'file_id' => File::factory(),
            'number' => 1,
        ];
    }
}

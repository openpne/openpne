<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\TimelinePost;
use App\Models\TimelinePostImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimelinePostImage>
 */
class TimelinePostImageFactory extends Factory
{
    protected $model = TimelinePostImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'timeline_post_id' => TimelinePost::factory(),
            'file_id' => File::factory(),
            'number' => 1,
        ];
    }
}

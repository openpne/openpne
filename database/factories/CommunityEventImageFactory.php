<?php

namespace Database\Factories;

use App\Models\CommunityEvent;
use App\Models\CommunityEventImage;
use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunityEventImage>
 */
class CommunityEventImageFactory extends Factory
{
    protected $model = CommunityEventImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => CommunityEvent::factory(),
            'file_id' => File::factory(),
            'number' => 1,
        ];
    }
}

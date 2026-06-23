<?php

namespace Database\Factories;

use App\Models\Diary;
use App\Models\DiaryImage;
use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiaryImage>
 */
class DiaryImageFactory extends Factory
{
    protected $model = DiaryImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'diary_id' => Diary::factory(),
            'file_id' => File::factory(),
            'number' => 1,
        ];
    }
}

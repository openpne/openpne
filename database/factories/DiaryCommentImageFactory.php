<?php

namespace Database\Factories;

use App\Models\DiaryComment;
use App\Models\DiaryCommentImage;
use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiaryCommentImage>
 */
class DiaryCommentImageFactory extends Factory
{
    protected $model = DiaryCommentImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'diary_comment_id' => DiaryComment::factory(),
            'file_id' => File::factory(),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\GroupEventComment;
use App\Models\GroupEventCommentImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupEventCommentImage>
 */
class GroupEventCommentImageFactory extends Factory
{
    protected $model = GroupEventCommentImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => GroupEventComment::factory(),
            'file_id' => File::factory(),
            'number' => 1,
        ];
    }
}

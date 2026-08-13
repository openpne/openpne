<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\GroupTopicComment;
use App\Models\GroupTopicCommentImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupTopicCommentImage>
 */
class GroupTopicCommentImageFactory extends Factory
{
    protected $model = GroupTopicCommentImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => GroupTopicComment::factory(),
            'file_id' => File::factory(),
            'number' => 1,
        ];
    }
}

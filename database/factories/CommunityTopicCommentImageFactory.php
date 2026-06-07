<?php

namespace Database\Factories;

use App\Models\CommunityTopicComment;
use App\Models\CommunityTopicCommentImage;
use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunityTopicCommentImage>
 */
class CommunityTopicCommentImageFactory extends Factory
{
    protected $model = CommunityTopicCommentImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => CommunityTopicComment::factory(),
            'file_id' => File::factory(),
            'number' => 1,
        ];
    }
}

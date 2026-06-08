<?php

namespace Database\Factories;

use App\Models\CommunityEventComment;
use App\Models\CommunityEventCommentImage;
use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunityEventCommentImage>
 */
class CommunityEventCommentImageFactory extends Factory
{
    protected $model = CommunityEventCommentImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => CommunityEventComment::factory(),
            'file_id' => File::factory(),
            'number' => 1,
        ];
    }
}

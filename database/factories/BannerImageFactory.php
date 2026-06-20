<?php

namespace Database\Factories;

use App\Models\BannerImage;
use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BannerImage>
 */
class BannerImageFactory extends Factory
{
    protected $model = BannerImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'file_id' => File::factory()->state(['type' => 'image/png']),
            'url' => null,
            'name' => $this->faker->words(2, true),
        ];
    }

    /** Point the File's owner back at this image, as the upload action does, so FilePolicy resolves it. */
    public function configure(): static
    {
        return $this->afterCreating(function (BannerImage $image): void {
            $image->file()->update([
                'related_entity_type' => 'bannerImage',
                'related_entity_id' => $image->getKey(),
            ]);
        });
    }
}

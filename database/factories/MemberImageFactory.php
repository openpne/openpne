<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\Member;
use App\Models\MemberImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberImage>
 */
class MemberImageFactory extends Factory
{
    protected $model = MemberImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            // Member-owned, as withdrawal finds an avatar's file by its owner.
            'file_id' => fn (array $attributes) => File::factory()->create([
                'related_entity_type' => 'member',
                'related_entity_id' => $attributes['member_id'],
            ])->getKey(),
        ];
    }
}

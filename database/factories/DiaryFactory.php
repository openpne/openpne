<?php

namespace Database\Factories;

use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Diary>
 */
class DiaryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraphs(2, true),
            'visibility' => Visibility::Members,
        ];
    }

    public function private(): static
    {
        return $this->state(['visibility' => Visibility::Private]);
    }

    public function friends(): static
    {
        return $this->state(['visibility' => Visibility::Friends]);
    }
}

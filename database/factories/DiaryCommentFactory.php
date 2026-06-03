<?php

namespace Database\Factories;

use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DiaryComment>
 */
class DiaryCommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'diary_id' => Diary::factory(),
            'member_id' => Member::factory(),
            'number' => 1,
            'body' => fake()->paragraph(),
        ];
    }
}

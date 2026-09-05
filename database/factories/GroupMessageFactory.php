<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupMessage>
 */
class GroupMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'member_id' => Member::factory(),
            'in_reply_to_id' => null,
            'body' => fake()->sentence(),
        ];
    }

    public function withdrawnAuthor(): static
    {
        return $this->state(fn (): array => ['member_id' => null]);
    }
}

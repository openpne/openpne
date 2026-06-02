<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MemberProfile>
 */
class MemberProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'profile_id' => Profile::factory(),
            'profile_option_id' => null,
            'value' => fake()->word(),
            'value_datetime' => null,
            'visibility' => Visibility::Members,
        ];
    }
}

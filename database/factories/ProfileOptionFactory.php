<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Models\ProfileOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProfileOption>
 */
class ProfileOptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'sort_order' => 0,
        ];
    }
}

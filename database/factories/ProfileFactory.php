<?php

namespace Database\Factories;

use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'field_'.fake()->unique()->numerify('######'),
            'is_required' => false,
            'is_unique' => false,
            'is_edit_public_flag' => true,
            'default_public_flag' => Profile::PUBLIC_FLAG_SNS,
            'form_type' => 'input',
            'value_type' => 'string',
            'is_disp_regist' => true,
            'is_disp_config' => true,
            'is_disp_search' => true,
            'is_public_web' => false,
            'value_regexp' => null,
            'value_min' => null,
            'value_max' => null,
            'sort_order' => 0,
        ];
    }

    public function preset(string $key): static
    {
        return $this->state(fn () => ['name' => 'op_preset_'.$key]);
    }
}

<?php

namespace Tests\Feature\Profile;

use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Models\ProfileOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberProfileDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_text_value_renders_verbatim(): void
    {
        $value = $this->valueFor(['form_type' => 'input'], ['value' => 'Hello there']);

        $this->assertSame('Hello there', $value->displayValue('ja_JP'));
    }

    public function test_preset_select_renders_the_localised_choice_label(): void
    {
        // OpenPNE 3 stores the choice value (Man), not M.
        $value = $this->valueFor(['name' => 'op_preset_sex', 'form_type' => 'select'], ['value' => 'Man']);

        $this->assertSame('男性', $value->displayValue('ja_JP'));
        $this->assertSame('Man', $value->displayValue('en'));
    }

    public function test_custom_select_renders_the_option_label(): void
    {
        $profile = Profile::factory()->create(['name' => 'fav_color', 'form_type' => 'select']);
        $option = ProfileOption::factory()->create(['profile_id' => $profile->getKey()]);
        $option->setLabel('ja_JP', '赤');
        $value = MemberProfile::factory()->create([
            'profile_id' => $profile->getKey(),
            'profile_option_id' => $option->getKey(),
            'value' => '',
        ]);

        $this->assertSame('赤', $value->displayValue('ja_JP'));
    }

    public function test_date_value_formats_the_datetime(): void
    {
        $value = $this->valueFor(
            ['name' => 'op_preset_birthday', 'form_type' => 'date'],
            ['value' => '1990-01-02', 'value_datetime' => '1990-01-02 00:00:00'],
        );

        $this->assertSame('1990-01-02', $value->displayValue('ja_JP'));
    }

    public function test_country_value_renders_the_localised_country_name(): void
    {
        $value = $this->valueFor(['name' => 'op_preset_country', 'form_type' => 'country_select'], ['value' => 'JP']);

        $this->assertSame('日本', $value->displayValue('ja_JP'));
        $this->assertSame('Japan', $value->displayValue('en'));
    }

    public function test_region_value_renders_the_localised_region_name(): void
    {
        $value = $this->valueFor(
            ['name' => 'op_preset_region', 'form_type' => 'region_select', 'value_type' => 'JP'],
            ['value' => 'Tokyo'],
        );

        $this->assertSame('東京都', $value->displayValue('ja_JP'));
        $this->assertSame('Tokyo', $value->displayValue('en'));
    }

    /** @param array<string, mixed> $profileAttrs @param array<string, mixed> $valueAttrs */
    private function valueFor(array $profileAttrs, array $valueAttrs): MemberProfile
    {
        $profile = Profile::factory()->create($profileAttrs);

        return MemberProfile::factory()->create(array_merge([
            'member_id' => Member::factory(),
            'profile_id' => $profile->getKey(),
        ], $valueAttrs));
    }
}

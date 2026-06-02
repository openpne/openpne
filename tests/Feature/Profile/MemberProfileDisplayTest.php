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
        $value = $this->valueFor(['name' => 'op_preset_sex', 'form_type' => 'select'], ['value' => 'M']);

        $this->assertSame('男性', $value->displayValue('ja_JP'));
        $this->assertSame('Male', $value->displayValue('en'));
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

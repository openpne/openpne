<?php

namespace Tests\Feature\Profile;

use App\Features\Profile\AgeVisibility;
use App\Models\Member;
use App\Support\Feature;
use App\Support\PreferenceKey;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\Rules\Enum;
use Tests\TestCase;

class AgeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_options_exclude_web_public(): void
    {
        $this->assertSame(
            [Visibility::Members, Visibility::Friends, Visibility::Private],
            AgeVisibility::options(),
        );
    }

    public function test_the_rule_rejects_web_public(): void
    {
        $rule = AgeVisibility::rule();

        $this->assertTrue($this->passes($rule, (string) Visibility::Friends->value));
        $this->assertFalse($this->passes($rule, (string) Visibility::Open->value));
        $this->assertFalse($this->passes($rule, '99'));
    }

    public function test_default_for_is_private_when_unset(): void
    {
        $member = Member::factory()->create();

        $this->assertSame(Visibility::Private, AgeVisibility::defaultFor($member));
    }

    public function test_default_for_reflects_a_stored_choice(): void
    {
        $member = Member::factory()->create();
        $member->setPreference(PreferenceKey::AgeVisibility, Visibility::Friends);

        $this->assertSame(Visibility::Friends, AgeVisibility::defaultFor($member));
    }

    public function test_default_for_clamps_a_stored_web_public_to_members_when_disabled(): void
    {
        $member = Member::factory()->create();
        $member->setPreference(PreferenceKey::AgeVisibility, Visibility::Open);

        $this->assertSame(Visibility::Members, AgeVisibility::defaultFor($member));
    }

    public function test_options_include_web_public_first_when_enabled(): void
    {
        $this->setSnsSetting(SnsSettingKey::AllowWebPublicAge, true);

        $this->assertSame(
            [Visibility::Open, Visibility::Members, Visibility::Friends, Visibility::Private],
            AgeVisibility::options(),
        );
    }

    public function test_the_rule_allows_web_public_when_enabled(): void
    {
        $this->setSnsSetting(SnsSettingKey::AllowWebPublicAge, true);

        $this->assertTrue($this->passes(AgeVisibility::rule(), (string) Visibility::Open->value));
    }

    public function test_default_for_preselects_a_stored_web_public_when_enabled(): void
    {
        $this->setSnsSetting(SnsSettingKey::AllowWebPublicAge, true);
        $member = Member::factory()->create();
        $member->setPreference(PreferenceKey::AgeVisibility, Visibility::Open);

        $this->assertSame(Visibility::Open, AgeVisibility::defaultFor($member));
    }

    public function test_options_drop_friends_while_the_unit_is_off(): void
    {
        $this->setSnsSetting(Feature::Friend->settingKey(), false);

        $this->assertSame([Visibility::Members, Visibility::Private], AgeVisibility::options());
        $this->assertFalse($this->passes(AgeVisibility::rule(), (string) Visibility::Friends->value));
    }

    public function test_a_stored_friends_survives_the_unit_going_off(): void
    {
        $this->setSnsSetting(Feature::Friend->settingKey(), false);
        $member = Member::factory()->create();
        $member->setPreference(PreferenceKey::AgeVisibility, Visibility::Friends);

        $this->assertSame(Visibility::Friends, AgeVisibility::defaultFor($member));
        $this->assertSame(
            [Visibility::Members, Visibility::Friends, Visibility::Private],
            AgeVisibility::optionsFor($member),
        );
        $this->assertTrue($this->passes(AgeVisibility::ruleFor($member), (string) Visibility::Friends->value));
    }

    public function test_a_member_storing_another_tier_is_not_offered_friends(): void
    {
        $this->setSnsSetting(Feature::Friend->settingKey(), false);
        $member = Member::factory()->create();
        $member->setPreference(PreferenceKey::AgeVisibility, Visibility::Members);

        $this->assertSame([Visibility::Members, Visibility::Private], AgeVisibility::optionsFor($member));
        $this->assertFalse($this->passes(AgeVisibility::ruleFor($member), (string) Visibility::Friends->value));
    }

    public function test_friends_stay_offered_to_everyone_while_the_unit_is_on(): void
    {
        $member = Member::factory()->create();

        $this->assertSame(
            [Visibility::Members, Visibility::Friends, Visibility::Private],
            AgeVisibility::optionsFor($member),
        );
        $this->assertTrue($this->passes(AgeVisibility::ruleFor($member), (string) Visibility::Friends->value));
    }

    private function passes(Enum $rule, string $value): bool
    {
        return validator(['age_visibility' => $value], ['age_visibility' => ['required', $rule]])->passes();
    }
}

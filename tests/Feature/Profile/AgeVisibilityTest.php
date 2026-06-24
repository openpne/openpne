<?php

namespace Tests\Feature\Profile;

use App\Features\Profile\AgeVisibility;
use App\Models\Member;
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
        // With web-public off the setter does not offer Open (it conveys no visibility then), so a
        // stored Open pre-selects as Members.
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

    private function passes(Enum $rule, string $value): bool
    {
        return validator(['age_visibility' => $value], ['age_visibility' => ['required', $rule]])->passes();
    }
}

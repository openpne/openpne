<?php

namespace Tests\Feature\Profile;

use App\Features\Profile\AgeVisibility;
use App\Models\Member;
use App\Support\PreferenceKey;
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

    public function test_default_for_clamps_a_stored_web_public_to_members(): void
    {
        // An OpenPNE 3 web-public age upgrades in as Open, which the setter does not offer; among
        // non-guests it already behaves as Members, so it pre-selects as Members.
        $member = Member::factory()->create();
        $member->setPreference(PreferenceKey::AgeVisibility, Visibility::Open);

        $this->assertSame(Visibility::Members, AgeVisibility::defaultFor($member));
    }

    private function passes(Enum $rule, string $value): bool
    {
        return validator(['age_visibility' => $value], ['age_visibility' => ['required', $rule]])->passes();
    }
}

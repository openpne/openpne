<?php

namespace Tests\Feature\Profile;

use App\Features\Profile\Queries\EditProfileFields;
use App\Features\Profile\Queries\RegistrationFields;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Support\Feature;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileFieldVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_options_offer_open_only_when_web_public(): void
    {
        $web = Profile::factory()->make(['is_public_web' => true]);
        $this->assertSame(
            [Visibility::Open, Visibility::Members, Visibility::Friends, Visibility::Private],
            $web->visibilityOptions(),
        );

        $closed = Profile::factory()->make(['is_public_web' => false]);
        $this->assertSame(
            [Visibility::Members, Visibility::Friends, Visibility::Private],
            $closed->visibilityOptions(),
        );
    }

    public function test_options_drop_friends_while_the_unit_is_off(): void
    {
        $this->setSnsSetting(Feature::Friend->settingKey(), false);
        $profile = Profile::factory()->make(['is_public_web' => false]);

        $this->assertSame([Visibility::Members, Visibility::Private], $profile->visibilityOptions());
        $this->assertSame(
            [Visibility::Members, Visibility::Friends, Visibility::Private],
            $profile->visibilityOptions(Visibility::Friends),
        );
    }

    public function test_a_value_stored_at_friends_keeps_that_selection(): void
    {
        $this->setSnsSetting(Feature::Friend->settingKey(), false);
        $member = Member::factory()->create();
        $profile = Profile::factory()->create();
        MemberProfile::factory()->create([
            'member_id' => $member->getKey(),
            'profile_id' => $profile->getKey(),
            'visibility' => Visibility::Friends,
        ]);

        $field = app(EditProfileFields::class)($member)->firstOrFail();

        $this->assertSame(Visibility::Friends, $field->visibility);
        $this->assertContains(Visibility::Friends, $field->profile->visibilityOptions($field->visibility));
    }

    public function test_a_field_the_member_has_no_value_for_clamps_the_admin_default(): void
    {
        // Nothing is stored, so the field default is seeding a new value: it clamps to an offered
        // audience (visibly, in the select) rather than sticking to a dead tier.
        $this->setSnsSetting(Feature::Friend->settingKey(), false);
        $member = Member::factory()->create();
        Profile::factory()->create(['default_visibility' => Visibility::Friends]);

        $field = app(EditProfileFields::class)($member)->firstOrFail();

        $this->assertSame(Visibility::Members, $field->visibility);
        $this->assertNotContains(Visibility::Friends, $field->profile->visibilityOptions($field->visibility));
    }

    public function test_a_value_stored_web_public_on_a_closed_field_still_clamps(): void
    {
        // Unchanged by the friend gate: Open differs from Members only by the guest visibility
        // is_public_web already withdrew, so this clamp narrows rather than widens.
        $member = Member::factory()->create();
        $profile = Profile::factory()->create(['is_public_web' => false]);
        MemberProfile::factory()->create([
            'member_id' => $member->getKey(),
            'profile_id' => $profile->getKey(),
            'visibility' => Visibility::Open,
        ]);

        $field = app(EditProfileFields::class)($member)->firstOrFail();

        $this->assertSame(Visibility::Members, $field->visibility);
    }

    public function test_the_registration_form_pre_selects_an_offered_audience(): void
    {
        $this->setSnsSetting(Feature::Friend->settingKey(), false);
        Profile::factory()->create(['default_visibility' => Visibility::Friends]);

        $field = app(RegistrationFields::class)()->firstOrFail();

        $this->assertSame(Visibility::Members, $field->visibility);
        $this->assertContains($field->visibility, $field->profile->visibilityOptions());
    }

    public function test_the_registration_form_keeps_an_offered_default(): void
    {
        Profile::factory()->create(['default_visibility' => Visibility::Friends]);

        $this->assertSame(Visibility::Friends, app(RegistrationFields::class)()->firstOrFail()->visibility);
    }
}

<?php

namespace Tests\Feature\Profile;

use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Models\RegistrationToken;
use App\Support\Feature;
use App\Support\PreferenceKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Saving a profile while the friend unit is off. The form re-posts every field's audience and the
 * age gate on each save, so anything the picker stopped offering would be rewritten by a save that
 * had nothing to do with it — the regression this pins.
 */
class ProfileFriendOffAudienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(Feature::Friend->settingKey(), false);
    }

    private function fieldStoredAtFriends(Member $member): Profile
    {
        $profile = Profile::factory()->create(['form_type' => 'input']);
        MemberProfile::factory()->create([
            'member_id' => $member->getKey(),
            'profile_id' => $profile->getKey(),
            'value' => 'ramen',
            'visibility' => Visibility::Friends,
        ]);

        return $profile;
    }

    public function test_an_unrelated_save_leaves_a_field_stored_at_friends_alone(): void
    {
        $member = Member::factory()->create();
        $profile = $this->fieldStoredAtFriends($member);

        // The payload the edit form renders for this member: the value and audience it showed.
        $this->actingAs($member)->post('/member/edit/profile', [
            'name' => 'Renamed',
            'profile' => [$profile->getKey() => 'ramen'],
            'visibility' => [$profile->getKey() => (string) Visibility::Friends->value],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('member_profiles', [
            'member_id' => $member->getKey(),
            'profile_id' => $profile->getKey(),
            'visibility' => Visibility::Friends->value,
        ]);
    }

    public function test_an_unrelated_save_leaves_a_stored_age_audience_alone(): void
    {
        $member = Member::factory()->create();
        Profile::factory()->preset('birthday')->create(['form_type' => 'date', 'is_disp_config' => false]);
        $member->setPreference(PreferenceKey::AgeVisibility, Visibility::Friends);

        $this->actingAs($member)->post('/member/edit/profile', [
            'name' => 'Renamed',
            'age_visibility' => (string) Visibility::Friends->value,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->getKey(),
            'key' => 'age_visibility',
            'value' => (string) Visibility::Friends->value,
        ]);
    }

    public function test_the_classic_edit_form_offers_what_the_member_stores(): void
    {
        $member = Member::factory()->create();
        $this->fieldStoredAtFriends($member);

        $this->actingAs($member)->get('/member/edit/profile')
            ->assertOk()
            ->assertSee('<option value="2" selected>', false);
    }

    public function test_the_modern_edit_form_offers_what_the_member_stores(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();
        $stored = $this->fieldStoredAtFriends($member);
        $fresh = Profile::factory()->create(['form_type' => 'input', 'sort_order' => 1]);
        Profile::factory()->preset('birthday')->create(['form_type' => 'date', 'is_disp_config' => false]);
        $member->setPreference(PreferenceKey::AgeVisibility, Visibility::Friends);

        $this->actingAs($member)->get('/member/edit/profile')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('member/edit-profile')
                ->where('form.fields.0.id', $stored->getKey())
                ->where('form.fields.0.visibility', Visibility::Friends->value)
                ->where('form.fields.0.visibilityOptions', fn ($options) => collect($options)->pluck('value')->all() === [1, 2, 3])
                // A field this member has no value for is new content: no Friends on offer.
                ->where('form.fields.1.id', $fresh->getKey())
                ->where('form.fields.1.visibilityOptions', fn ($options) => collect($options)->pluck('value')->all() === [1, 3])
                ->where('form.age.value', Visibility::Friends->value)
                ->where('form.age.options', fn ($options) => collect($options)->pluck('value')->all() === [1, 2, 3]));
    }

    public function test_a_field_the_member_does_not_store_at_friends_cannot_be_moved_there(): void
    {
        $member = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'input']);

        $this->actingAs($member)->post('/member/edit/profile', [
            'name' => $member->name,
            'profile' => [$profile->getKey() => 'ramen'],
            'visibility' => [$profile->getKey() => (string) Visibility::Friends->value],
        ])->assertSessionHasErrors("visibility.{$profile->getKey()}");
    }

    public function test_the_age_gate_cannot_be_moved_to_friends(): void
    {
        $member = Member::factory()->create();
        Profile::factory()->preset('birthday')->create(['form_type' => 'date', 'is_disp_config' => false]);

        $this->actingAs($member)->post('/member/edit/profile', [
            'name' => $member->name,
            'age_visibility' => (string) Visibility::Friends->value,
        ])->assertSessionHasErrors('age_visibility');
    }

    public function test_registration_pre_selects_and_stores_the_audience_the_member_saw(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $profile = Profile::factory()->create(['form_type' => 'input', 'default_visibility' => Visibility::Friends]);
        $token = $this->issueToken();

        $this->get("/register/{$token}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('auth/register-complete')
                ->where('fields.0.visibility', Visibility::Members->value)
                ->where('fields.0.visibilityOptions', fn ($options) => collect($options)->pluck('value')->all() === [1, 3]));

        $this->post("/register/{$token}", [
            'name' => 'Newcomer',
            'password' => 'sufficiently-long-pw',
            'password_confirmation' => 'sufficiently-long-pw',
            'profile' => [$profile->getKey() => 'ramen'],
            'visibility' => [$profile->getKey() => (string) Visibility::Members->value],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('member_profiles', [
            'profile_id' => $profile->getKey(),
            'value' => 'ramen',
            'visibility' => Visibility::Members->value,
        ]);
    }

    public function test_the_unit_switched_on_offers_friends_everywhere(): void
    {
        $this->setSnsSetting(Feature::Friend->settingKey(), true);
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'input']);
        Profile::factory()->preset('birthday')->create(['form_type' => 'date', 'is_disp_config' => false, 'sort_order' => 1]);

        $this->actingAs($member)->get('/member/edit/profile')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('form.fields.0.visibilityOptions', fn ($options) => collect($options)->pluck('value')->all() === [1, 2, 3])
                ->where('form.age.options', fn ($options) => collect($options)->pluck('value')->all() === [1, 2, 3]));

        $this->actingAs($member)->post('/member/edit/profile', [
            'name' => $member->name,
            'profile' => [$profile->getKey() => 'ramen'],
            'visibility' => [$profile->getKey() => (string) Visibility::Friends->value],
            'age_visibility' => (string) Visibility::Friends->value,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('member_profiles', [
            'profile_id' => $profile->getKey(),
            'visibility' => Visibility::Friends->value,
        ]);
    }

    /** Issue a live pending registration and return the raw token its link would carry. */
    private function issueToken(): string
    {
        $raw = Str::random(40);
        RegistrationToken::create([
            'email' => 'newcomer@example.com',
            'token' => hash('sha256', $raw),
            'created_at' => now(),
        ]);

        return $raw;
    }
}

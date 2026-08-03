<?php

namespace Tests\Feature\Member;

use App\Models\Member;
use App\Support\Feature;
use App\Support\PreferenceKey;
use App\Support\SnsSettingKey;
use App\Support\Surface;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The member's stored default audience for new diaries while the friend unit is off. The preference
 * seeds new entries rather than describing an existing one, so it clamps where it is *shown* — and
 * the stored row is left as the member set it until they save that section themselves.
 */
class MemberConfigFriendOffAudienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::DiaryAllowWebPublic, false);
        $this->setSnsSetting(Feature::Friend->settingKey(), false);
    }

    private function memberPreferringFriends(): Member
    {
        $member = Member::factory()->create();
        $member->setPreference(PreferenceKey::DiaryDefaultVisibility, Visibility::Friends);

        return $member;
    }

    public function test_an_unrelated_section_save_leaves_the_stored_preference_alone(): void
    {
        $member = $this->memberPreferringFriends();

        $this->actingAs($member)
            ->post('/member/config/surface', ['preferred_surface' => Surface::Modern->value])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->getKey(),
            'key' => 'diary_default_visibility',
            'value' => (string) Visibility::Friends->value,
        ]);
    }

    public function test_the_compose_form_pre_selects_the_clamped_default(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $member = $this->memberPreferringFriends();

        $this->actingAs($member)->get('/diary/new')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('diary/new')
                // Members, and it is one of the options on screen — not a hidden substitution.
                ->where('defaultVisibility', (string) Visibility::Members->value)
                ->where('visibilityOptions', fn ($options) => collect($options)->pluck('value')->all() === ['1', '3']));
    }

    public function test_the_classic_setting_shows_the_clamped_default_without_a_friends_option(): void
    {
        $member = $this->memberPreferringFriends();

        $this->actingAs($member)->get('/member/config?category=diary')
            ->assertOk()
            ->assertSee('<option value="1" selected>', false)
            ->assertDontSee('<option value="2"', false);
    }

    public function test_the_modern_setting_shows_the_clamped_default_without_a_friends_option(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $member = $this->memberPreferringFriends();

        $this->actingAs($member)->get('/member/config')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('form.diary.value', (string) Visibility::Members->value)
                ->where('form.diary.options', fn ($options) => collect($options)->pluck('value')->all() === ['1', '3']));
    }

    public function test_the_setting_cannot_be_saved_to_friends(): void
    {
        $member = $this->memberPreferringFriends();

        $this->actingAs($member)
            ->post('/member/config/diary', ['diary_default_visibility' => (string) Visibility::Friends->value])
            ->assertSessionHasErrors('diary_default_visibility');
    }

    public function test_saving_the_section_persists_what_the_member_confirmed(): void
    {
        $member = $this->memberPreferringFriends();

        $this->actingAs($member)
            ->post('/member/config/diary', ['diary_default_visibility' => (string) Visibility::Members->value])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->getKey(),
            'key' => 'diary_default_visibility',
            'value' => (string) Visibility::Members->value,
        ]);
    }

    public function test_the_unit_switched_on_restores_the_stored_preference(): void
    {
        $this->setSnsSetting(Feature::Friend->settingKey(), true);
        config(['openpne.surface_mode' => 'modern_default']);
        $member = $this->memberPreferringFriends();

        $this->actingAs($member)->get('/diary/new')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('defaultVisibility', (string) Visibility::Friends->value));
    }
}

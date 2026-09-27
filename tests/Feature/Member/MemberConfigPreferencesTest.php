<?php

namespace Tests\Feature\Member;

use App\Features\Profile\ProfileVisibilityPolicy;
use App\Models\Member;
use App\Models\Profile;
use App\Support\PreferenceKey;
use App\Support\SnsSettingKey;
use App\Support\Surface;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

class MemberConfigPreferencesTest extends TestCase
{
    use CapturesSecurityLog;
    use RefreshDatabase;

    public function test_updating_the_diary_default_writes_the_preference(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/diary', [
            'diary_default_visibility' => (string) Visibility::Friends->value,
        ])->assertRedirect(route('member.config', ['category' => 'diary']));

        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'diary_default_visibility', 'value' => '2',
        ]);
    }

    public function test_updating_the_diary_default_rejects_an_invalid_value(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/diary', ['diary_default_visibility' => '99'])
            ->assertSessionHasErrors('diary_default_visibility');

        $this->assertDatabaseMissing('member_preferences', [
            'member_id' => $member->id, 'key' => 'diary_default_visibility',
        ]);
    }

    public function test_updating_age_visibility_writes_the_preference(): void
    {
        $member = Member::factory()->create();
        Profile::factory()->preset('birthday')->create(['form_type' => 'date']);

        $this->actingAs($member)->post('/member/config/age', [
            'age_visibility' => (string) Visibility::Friends->value,
        ])->assertRedirect(route('member.config', ['category' => 'publicFlag']));

        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'age_visibility', 'value' => '2',
        ]);
    }

    public function test_a_crafted_age_post_without_a_birthday_item_persists_nothing(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/age', [
            'age_visibility' => (string) Visibility::Friends->value,
        ])->assertRedirect(route('member.config'));

        $this->assertDatabaseMissing('member_preferences', [
            'member_id' => $member->id, 'key' => 'age_visibility',
        ]);
    }

    public function test_updating_age_visibility_rejects_an_invalid_value(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/age', ['age_visibility' => '99'])
            ->assertSessionHasErrors('age_visibility');

        $this->assertDatabaseMissing('member_preferences', [
            'member_id' => $member->id, 'key' => 'age_visibility',
        ]);
    }

    public function test_updating_age_visibility_rejects_web_public_when_disabled(): void
    {
        // Web-public age is off by default, so Open is not an accepted choice.
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/age', [
            'age_visibility' => (string) Visibility::Open->value,
        ])->assertSessionHasErrors('age_visibility');

        $this->assertDatabaseMissing('member_preferences', [
            'member_id' => $member->id, 'key' => 'age_visibility',
        ]);
    }

    public function test_the_age_category_is_hidden_without_a_birthday_profile_item(): void
    {
        // No birthday item → no age to gate, and no profile-page choice either, so the category is
        // dead weight: absent from the nav and its URL folds into the landing (deliberate divergence
        // from OpenPNE 3's always-on).
        $this->setSnsSetting(SnsSettingKey::ProfileVisibilityPolicy, ProfileVisibilityPolicy::Members);
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config?category=publicFlag')
            ->assertOk()
            ->assertSee('Please select the item')
            ->assertDontSee('id="publicFlagForm"', false)
            ->assertDontSee('href="'.route('member.config', ['category' => 'publicFlag']).'"', false);
    }

    public function test_updating_age_visibility_accepts_web_public_when_enabled(): void
    {
        $this->setSnsSetting(SnsSettingKey::AllowWebPublicAge, true);
        $member = Member::factory()->create();
        Profile::factory()->preset('birthday')->create(['form_type' => 'date']);

        $this->actingAs($member)->post('/member/config/age', [
            'age_visibility' => (string) Visibility::Open->value,
        ])->assertRedirect(route('member.config', ['category' => 'publicFlag']));

        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'age_visibility', 'value' => '0',
        ]);
    }

    public function test_changing_the_surface_alone_preserves_a_stored_open_diary_default(): void
    {
        // Web-public off: DiaryVisibility::defaultFor() clamps a stored Open to Members at read time,
        // but the stored row must stay Open — a surface change must not write the clamped value back.
        $this->setSnsSetting(SnsSettingKey::DiaryAllowWebPublic, false);
        $member = Member::factory()->create();
        $member->setPreference(PreferenceKey::DiaryDefaultVisibility, Visibility::Open);

        $this->actingAs($member)->post('/member/config/surface', ['preferred_surface' => 'modern']);

        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'diary_default_visibility', 'value' => '0',
        ]);
    }

    public function test_a_durable_surface_choice_drives_resolution_on_other_features(): void
    {
        $member = Member::factory()->create();

        // Default surface is Classic; choosing Modern flips a canonical feature route to Modern.
        $this->actingAs($member)->post('/member/config/surface', ['preferred_surface' => 'modern']);
        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'preferred_surface', 'value' => 'modern',
        ]);
        $this->actingAs($member)->get('/friend/list')
            ->assertInertia(fn (Assert $page) => $page->component('friend/list'));

        // Switching to Classic flips it back.
        $this->actingAs($member)->post('/member/config/surface', ['preferred_surface' => 'classic']);
        $this->actingAs($member)->get('/friend/list')
            ->assertOk()->assertSee('id="page_friend_list"', false);
    }

    public function test_a_classic_choice_from_the_modern_surface_lands_on_the_classic_config_page(): void
    {
        // Choosing Classic must land on the Classic category page, not back on a Modern render —
        // the just-written preference resolves the chosen surface on the redirect target.
        $member = Member::factory()->create();
        $member->setPreferredSurface(Surface::Modern); // currently Modern, so choosing Classic is a real change

        $this->actingAs($member)->post('/member/config/surface', ['preferred_surface' => 'classic'])
            ->assertRedirect(route('member.config', ['category' => 'general']));

        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'preferred_surface', 'value' => 'classic',
        ]);
    }

    public function test_modern_only_hides_the_surface_picker_and_rejects_a_posted_choice(): void
    {
        // Both halves: the picker is not served, and a crafted POST is still rejected.
        config(['openpne.surface_mode' => 'modern_only']);
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('form.surface'));

        $this->actingAs($member)->post('/member/config/surface', ['preferred_surface' => 'classic'])
            ->assertForbidden();

        $this->assertDatabaseMissing('member_preferences', [
            'member_id' => $member->id, 'key' => 'preferred_surface',
        ]);
    }

    public function test_saving_the_current_surface_is_a_no_op_so_an_unset_member_stays_unset(): void
    {
        // The default is Modern here, so an unset member saving Modern stays unset and keeps
        // following it.
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/surface', ['preferred_surface' => 'modern']);

        $this->assertDatabaseMissing('member_preferences', [
            'member_id' => $member->id, 'key' => 'preferred_surface',
        ]);
        $this->actingAs($member)->get('/friend/list')
            ->assertInertia(fn (Assert $page) => $page->component('friend/list'));
    }

    public function test_modern_ignores_the_category_query_and_stays_single_page(): void
    {
        // ?category= is a Classic concept; a Modern-resolved request must not 404 or branch on it.
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config?category=zzz')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('member/config'));
    }

    public function test_a_modern_save_redirect_carries_no_category(): void
    {
        // The diary POST is shared with Modern; the category param is gated to the Classic target.
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/diary', [
            'diary_default_visibility' => (string) Visibility::Friends->value,
        ])->assertRedirect(route('member.config'));
    }

    public function test_a_modern_preference_save_suppresses_the_page_flash(): void
    {
        // Modern announces the instant-apply diary preference inline next to the control; the
        // page flash is dropped so one save is never announced twice.
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/diary', [
            'diary_default_visibility' => (string) Visibility::Friends->value,
        ])->assertRedirect(route('member.config'))->assertSessionMissing('status');
    }

    public function test_a_classic_preference_save_keeps_the_page_flash(): void
    {
        // Classic category pages have no inline indicator, so the flash stays their save feedback.
        $member = Member::factory()->create();
        Profile::factory()->preset('birthday')->create(['form_type' => 'date']);

        $this->actingAs($member)->post('/member/config/diary', [
            'diary_default_visibility' => (string) Visibility::Friends->value,
        ])->assertSessionHas('status');

        $this->actingAs($member)->post('/member/config/age', [
            'age_visibility' => (string) Visibility::Friends->value,
        ])->assertSessionHas('status');
    }

    public function test_an_invalid_value_returns_to_its_category(): void
    {
        // The section forms POST to category-less routes, so the browser referer (->from) is what
        // carries the category back on a validation failure.
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->from(route('member.config', ['category' => 'diary']))
            ->post('/member/config/diary', ['diary_default_visibility' => '99'])
            ->assertRedirect(route('member.config', ['category' => 'diary']))
            ->assertSessionHasErrors('diary_default_visibility');

        $this->actingAs($member)
            ->from(route('member.config', ['category' => 'publicFlag']))
            ->post('/member/config/age', ['age_visibility' => '99'])
            ->assertRedirect(route('member.config', ['category' => 'publicFlag']))
            ->assertSessionHasErrors('age_visibility');
    }

    public function test_the_language_form_returns_to_the_language_category(): void
    {
        // Language posts to the shared locale.switch, which redirects to url()->previous(); from the
        // language category page that preserves ?category=language.
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->from(route('member.config', ['category' => 'language']))
            ->post(route('locale.switch'), ['locale' => 'en'])
            ->assertRedirect(route('member.config', ['category' => 'language']));
    }
}

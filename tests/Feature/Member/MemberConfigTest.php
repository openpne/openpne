<?php

namespace Tests\Feature\Member;

use App\Models\Member;
use App\Support\PreferenceKey;
use App\Support\SnsSettingKey;
use App\Support\Surface;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MemberConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/member/config')->assertRedirect('/login');
    }

    public function test_the_classic_landing_shows_the_category_nav_and_no_form(): void
    {
        // OpenPNE 3 member/config with no ?category=: LayoutB, the category pageNav, and the
        // "select an item" box — no section form yet.
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config')
            ->assertOk()
            ->assertSee('id="page_member_config"', false)
            ->assertSee('id="LayoutB"', false)
            ->assertSee('id="Left"', false)
            ->assertSee('class="parts pageNav"', false)
            ->assertSee('Please select the item')
            ->assertDontSee('id="member_config_diary"', false)
            ->assertDontSee('id="member_config_surface"', false);
    }

    public function test_the_category_nav_links_to_the_other_categories(): void
    {
        $member = Member::factory()->create();

        // On the diary page, diary is plain text and the other three are links.
        $this->actingAs($member)->get('/member/config?category=diary')
            ->assertOk()
            ->assertSee('id="LayoutB"', false)
            ->assertSee('href="'.route('member.config', ['category' => 'publicFlag']).'"', false)
            ->assertSee('href="'.route('member.config', ['category' => 'language']).'"', false)
            ->assertSee('href="'.route('member.config', ['category' => 'general']).'"', false)
            ->assertSee('href="'.route('member.config', ['category' => 'password']).'"', false)
            ->assertDontSee('href="'.route('member.config', ['category' => 'diary']).'"', false);
    }

    public function test_each_category_shows_only_its_section(): void
    {
        // Asserted by the section's form id (a `name="locale"` marker would be polluted by the global
        // side-banner language gadget).
        $sections = [
            'diary' => 'member_config_diary',
            'publicFlag' => 'member_config_age',
            'language' => 'member_config_language',
            'general' => 'member_config_surface',
            'password' => 'member_config_password',
        ];
        $member = Member::factory()->create();

        foreach ($sections as $category => $shownId) {
            $response = $this->actingAs($member)->get('/member/config?category='.$category)->assertOk();
            $response->assertSee('id="'.$shownId.'"', false);
            foreach (array_diff(array_values($sections), [$shownId]) as $hiddenId) {
                $response->assertDontSee('id="'.$hiddenId.'"', false);
            }
        }
    }

    public function test_an_unknown_category_renders_the_landing_not_404(): void
    {
        // BlockRoutesTest locks /member/config?category=profile as OK; unknown categories fall
        // through to the landing rather than 404 (only accessBlock redirects).
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config?category=profile')
            ->assertOk()
            ->assertSee('Please select the item')
            ->assertDontSee('id="member_config_diary"', false);

        $this->actingAs($member)->get('/member/config?category=zzz')->assertOk();
    }

    public function test_the_modern_page_renders_the_inertia_component(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/m/member/config')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('member/config')
                ->where('form.surface.value', 'classic') // preselected to the current surface (tenant default)
                ->where('form.surface.options', fn ($options) => count($options) === 2) // binary: no "default" option
                ->has('form.diary.options')
                ->where('form.age.value', '3') // default Private
                // Members/Friends/Private — Open (value "0") is absent regardless of locale.
                ->where('form.age.options', fn ($options) => collect($options)->pluck('value')->all() === ['1', '2', '3'])
            );
    }

    public function test_the_access_block_category_redirects_to_the_block_list(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config?category=accessBlock')
            ->assertRedirect(route('block.list'));
    }

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

        $this->actingAs($member)->post('/member/config/age', [
            'age_visibility' => (string) Visibility::Friends->value,
        ])->assertRedirect(route('member.config', ['category' => 'publicFlag']));

        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'age_visibility', 'value' => '2',
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

    public function test_age_options_include_web_public_when_enabled(): void
    {
        $this->setSnsSetting(SnsSettingKey::AllowWebPublicAge, true);
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/m/member/config')
            ->assertInertia(fn (Assert $page) => $page
                ->where('form.age.options', fn ($options) => collect($options)->pluck('value')->all() === ['0', '1', '2', '3']));
    }

    public function test_updating_age_visibility_accepts_web_public_when_enabled(): void
    {
        $this->setSnsSetting(SnsSettingKey::AllowWebPublicAge, true);
        $member = Member::factory()->create();

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
        config(['openpne.diary.allow_web_public' => false]);
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

        // Default tenant surface is Classic; choosing Modern flips a canonical feature route to Modern.
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

    public function test_a_classic_choice_from_the_modern_page_lands_on_the_canonical_url(): void
    {
        // The explicit /m/* URL is top of SurfaceResolver's order, so a Classic choice must leave it
        // for the canonical config URL, or the page would stay Modern (Codex High 2).
        $member = Member::factory()->create();
        $member->setPreferredSurface(Surface::Modern); // currently Modern, so choosing Classic is a real change

        $this->actingAs($member)->post('/m/member/config/surface', ['preferred_surface' => 'classic'])
            ->assertRedirect(route('member.config', ['category' => 'general']));

        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'preferred_surface', 'value' => 'classic',
        ]);
    }

    public function test_modern_only_tenant_shows_modern_and_does_not_false_pin(): void
    {
        // The "current surface" must honour the modern_only hard gate, not just the tenant default.
        // Otherwise an unset member on a modern_only tenant (default Classic) sees Modern, but the
        // form would call the current surface Classic and selecting Modern would wrongly pin them.
        config(['openpne.tenant_mode' => 'modern_only', 'openpne.tenant_default_surface' => 'classic']);
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/m/member/config')
            ->assertInertia(fn (Assert $page) => $page->where('form.surface.value', 'modern'));

        $this->actingAs($member)->post('/member/config/surface', ['preferred_surface' => 'modern']);

        $this->assertDatabaseMissing('member_preferences', [
            'member_id' => $member->id, 'key' => 'preferred_surface',
        ]);
    }

    public function test_saving_the_current_surface_is_a_no_op_so_an_unset_member_stays_unset(): void
    {
        // Binary UI has no "follow default" option; instead, saving the surface the member is already
        // on never pins them, so the operator can still move unset members later. Default is Modern
        // here; an unset member saving Modern stays unset and keeps following the default.
        config(['openpne.tenant_default_surface' => 'modern']);
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
        // The /m/* URL forces Modern; ?category= is a Classic concept and must not 404 or branch.
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/m/member/config?category=zzz')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('member/config'));
    }

    public function test_a_modern_save_redirect_carries_no_category(): void
    {
        // The diary/age POSTs are shared with Modern; the category param is gated to the Classic target.
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/m/member/config/diary', [
            'diary_default_visibility' => (string) Visibility::Friends->value,
        ])->assertRedirect(route('member.modern.config'));

        $this->actingAs($member)->post('/m/member/config/age', [
            'age_visibility' => (string) Visibility::Friends->value,
        ])->assertRedirect(route('member.modern.config'));
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

    public function test_a_guest_cannot_post_the_password_change(): void
    {
        $this->post('/member/config/password', [
            'current_password' => 'password',
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ])->assertRedirect('/login');
    }

    public function test_changing_the_password_with_the_correct_current_password(): void
    {
        // Factory password is 'password'.
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/password', [
            'current_password' => 'password',
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ])->assertRedirect(route('member.config', ['category' => 'password']));

        $this->assertTrue(Hash::check('new-secret-pass', $member->fresh()->password));
    }

    public function test_changing_the_password_rejects_a_wrong_current_password(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/password', [
            'current_password' => 'not-the-password',
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ])->assertSessionHasErrors('current_password');

        // Password unchanged.
        $this->assertTrue(Hash::check('password', $member->fresh()->password));
    }

    public function test_changing_the_password_rejects_a_mismatched_confirmation(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/password', [
            'current_password' => 'password',
            'password' => 'new-secret-pass',
            'password_confirmation' => 'different-pass',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $member->fresh()->password));
    }

    public function test_changing_the_password_rejects_a_too_short_password(): void
    {
        // Shared passwordRules() = Password::default() (min 8).
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/password', [
            'current_password' => 'password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', $member->fresh()->password));
    }

    public function test_the_current_session_survives_a_password_change(): void
    {
        // logoutOtherDevices re-syncs the current session's stored hash, so the acting session stays
        // authenticated (other devices are rejected on their next protected request, not tested here).
        $member = Member::factory()->create();
        $this->actingAs($member);

        $this->post('/member/config/password', [
            'current_password' => 'password',
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ])->assertRedirect(route('member.config', ['category' => 'password']));

        $this->get('/member/config')->assertOk();
    }

    public function test_a_modern_password_save_redirects_to_the_modern_config(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/m/member/config/password', [
            'current_password' => 'password',
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ])->assertRedirect(route('member.modern.config'));

        $this->assertTrue(Hash::check('new-secret-pass', $member->fresh()->password));
    }
}

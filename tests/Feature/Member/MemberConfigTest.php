<?php

namespace Tests\Feature\Member;

use App\Features\Member\Actions\ConfirmEmailChange;
use App\Features\Profile\ProfileVisibilityPolicy;
use App\Models\EmailChangeRequest;
use App\Models\Member;
use App\Models\Profile;
use App\Notifications\Member\EmailChangeConfirmationNotification;
use App\Notifications\Member\EmailChangeNoticeNotification;
use App\Support\PreferenceKey;
use App\Support\SnsSettingKey;
use App\Support\Surface;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

class MemberConfigTest extends TestCase
{
    use CapturesSecurityLog;
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
            ->assertSee('class="dparts pageNav"', false)
            ->assertSee('Please select the item')
            ->assertDontSee('id="diaryForm"', false)
            ->assertDontSee('id="generalForm"', false);
    }

    public function test_the_category_nav_links_to_the_other_categories(): void
    {
        $member = Member::factory()->create();
        Profile::factory()->preset('birthday')->create(['form_type' => 'date']); // offers the age category

        // On the diary page, diary is plain text and the other three are links.
        $this->actingAs($member)->get('/member/config?category=diary')
            ->assertOk()
            ->assertSee('id="LayoutB"', false)
            ->assertSee('href="'.route('member.config', ['category' => 'publicFlag']).'"', false)
            ->assertSee('href="'.route('member.config', ['category' => 'language']).'"', false)
            ->assertSee('href="'.route('member.config', ['category' => 'general']).'"', false)
            ->assertSee('href="'.route('member.config', ['category' => 'password']).'"', false)
            ->assertSee('href="'.route('member.config', ['category' => 'email']).'"', false)
            ->assertSee('href="'.route('member.config', ['category' => 'withdrawal']).'"', false)
            ->assertDontSee('href="'.route('member.config', ['category' => 'diary']).'"', false);
    }

    public function test_each_category_shows_only_its_section(): void
    {
        // Asserted by the section's form id (a `name="locale"` marker would be polluted by the global
        // side-banner language gadget).
        $sections = [
            'diary' => 'diaryForm',
            'publicFlag' => 'publicFlagForm',
            'language' => 'languageForm',
            'general' => 'generalForm',
            'password' => 'passwordForm',
            'email' => 'member_config_email',
            'withdrawal' => 'member_config_withdrawal',
        ];
        $member = Member::factory()->create();
        Profile::factory()->preset('birthday')->create(['form_type' => 'date']); // offers the age category

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
        // An unknown category falls through to the landing rather than 404.
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config?category=profile')
            ->assertOk()
            ->assertSee('Please select the item')
            ->assertDontSee('id="diaryForm"', false);

        $this->actingAs($member)->get('/member/config?category=zzz')->assertOk();
    }

    public function test_the_modern_page_renders_the_inertia_component(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('member/config')
                ->where('form.surface.value', 'modern') // preselected to the current surface (mode default)
                ->where('form.surface.options', fn ($options) => count($options) === 2) // binary: no "default" option
                ->has('form.diary.options')
                ->missing('form.age')
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

    public function test_the_modern_account_detail_pages_render(): void
    {
        // The consequential account forms live one level under the settings hub (Modern only;
        // Classic keeps its ?category= pages).
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config/email')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('member/config/email')->where('email', $member->email));

        $this->actingAs($member)->get('/member/config/password')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('member/config/password'));

        $this->actingAs($member)->get('/member/config/withdrawal')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('member/config/withdrawal'));
    }

    public function test_a_guest_is_redirected_from_the_account_detail_pages(): void
    {
        $this->get('/member/config/password')->assertRedirect('/login');
    }

    public function test_a_validation_failure_returns_to_the_detail_page(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->from('/member/config/password')
            ->post('/member/config/password', [
                'current_password' => 'not-the-password',
                'password' => 'new-secret-pass',
                'password_confirmation' => 'new-secret-pass',
            ])
            ->assertRedirect('/member/config/password')
            ->assertSessionHasErrors('current_password');
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
        // authenticated.
        $member = Member::factory()->create();
        $this->actingAs($member);

        $this->post('/member/config/password', [
            'current_password' => 'password',
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ])->assertRedirect(route('member.config', ['category' => 'password']));

        $this->get('/member/config')->assertOk();
    }

    public function test_a_modern_password_save_redirects_to_the_bare_config(): void
    {
        // Unlike the instant-apply preferences, the explicit password form keeps its flash on Modern.
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/password', [
            'current_password' => 'password',
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ])->assertRedirect(route('member.config'))->assertSessionHas('status');

        $this->assertTrue(Hash::check('new-secret-pass', $member->fresh()->password));
    }

    public function test_changing_the_password_rotates_the_remember_token(): void
    {
        // Rotating remember_token kills "remember me" cookies on every device.
        $member = Member::factory()->create(['remember_token' => 'old-remember-token']);

        $this->actingAs($member)->post('/member/config/password', [
            'current_password' => 'password',
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ])->assertRedirect(route('member.config', ['category' => 'password']));

        $this->assertNotSame('old-remember-token', $member->fresh()->remember_token);
    }

    public function test_a_device_with_a_stale_password_hash_is_logged_out(): void
    {
        // Changing the hash out of band stands in for another device's change, so the stale session
        // must redirect to login.
        $member = Member::factory()->create();
        $this->actingAs($member)->get('/member/config')->assertOk();

        $member->forceFill(['password' => Hash::make('changed-elsewhere')])->save();

        $this->get('/member/config')->assertRedirect('/login');
    }

    public function test_a_guest_cannot_post_the_withdrawal(): void
    {
        $this->post('/member/config/withdrawal', ['password' => 'password', 'confirm' => '1'])
            ->assertRedirect('/login');
    }

    public function test_withdrawing_deletes_the_member_and_logs_out(): void
    {
        Member::factory()->create(['id' => 1]); // reserve the un-withdrawable primary
        $member = Member::factory()->create();
        $this->actingAs($member);

        $this->post('/member/config/withdrawal', ['password' => 'password', 'confirm' => '1'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseMissing('members', ['id' => $member->id]);
        $this->get('/member/config')->assertRedirect('/login'); // logged out
    }

    public function test_withdrawing_rejects_a_wrong_password(): void
    {
        Member::factory()->create(['id' => 1]);
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/withdrawal', [
            'password' => 'not-the-password',
            'confirm' => '1',
        ])->assertSessionHasErrors('password');

        $this->assertModelExists($member);
    }

    public function test_withdrawing_requires_the_confirmation_checkbox(): void
    {
        Member::factory()->create(['id' => 1]);
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/withdrawal', ['password' => 'password'])
            ->assertSessionHasErrors('confirm');

        $this->assertModelExists($member);
    }

    public function test_the_primary_member_cannot_withdraw(): void
    {
        // id 1 is never withdrawable; rejected before the service so it is a 403, not a 500.
        $primary = Member::factory()->create(['id' => 1]);

        $this->actingAs($primary)->post('/member/config/withdrawal', [
            'password' => 'password',
            'confirm' => '1',
        ])->assertForbidden();

        $this->assertModelExists($primary);
    }

    public function test_the_leave_url_redirects_to_the_withdrawal_category(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/leave')
            ->assertRedirect(route('member.config', ['category' => 'withdrawal']));
    }

    public function test_withdrawing_purges_the_members_database_sessions(): void
    {
        // sessions.user_id has no FK, so deleting the member leaves rows behind; on the database driver
        // the withdrawal purges the member's other-device sessions outright.
        config()->set('session.driver', 'database');
        Member::factory()->create(['id' => 1]);
        $member = Member::factory()->create();
        DB::table('sessions')->insert([
            'id' => 'other-device-session',
            'user_id' => $member->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'agent',
            'payload' => 'x',
            'last_activity' => 1700000000,
        ]);

        $this->actingAs($member)->post('/member/config/withdrawal', [
            'password' => 'password',
            'confirm' => '1',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session']);
    }

    public function test_a_guest_cannot_request_an_email_change(): void
    {
        $this->post('/member/config/email', ['password' => 'password', 'new_email' => 'new@example.com'])
            ->assertRedirect('/login');
    }

    public function test_requesting_an_email_change_stores_a_pending_row_and_mails_both_addresses(): void
    {
        Notification::fake();
        $this->captureSecurityLog();
        $member = Member::factory()->create();
        $old = $member->email;

        $this->actingAs($member)->post('/member/config/email', [
            'password' => 'password',
            'new_email' => 'new@example.com',
        ])->assertRedirect(route('member.config', ['category' => 'email']));

        // The new address is the subject of an email change, so it is logged (unlike a password).
        $this->assertSame('new@example.com', $this->assertOneSecurityEvent('email.change_requested')['new_email']);

        $this->assertDatabaseHas('email_change_requests', [
            'member_id' => $member->id, 'new_email' => 'new@example.com',
        ]);
        // The login email is not touched until confirmation.
        $this->assertSame($old, $member->fresh()->email);

        // Confirmation to the NEW address, notice to the OLD address (both pinned literals).
        Notification::assertSentOnDemand(
            EmailChangeConfirmationNotification::class,
            fn ($n, $channels, $notifiable): bool => ($notifiable->routes['mail'] ?? null) === 'new@example.com',
        );
        // The old-address notice carries the raw cancel token whose hash is the stored cancel_token.
        $row = EmailChangeRequest::firstWhere('member_id', $member->id);
        $this->assertNotNull($row?->cancel_token);
        Notification::assertSentOnDemand(
            EmailChangeNoticeNotification::class,
            fn (EmailChangeNoticeNotification $n, $channels, $notifiable): bool => ($notifiable->routes['mail'] ?? null) === $old
                && hash('sha256', $n->rawCancelToken) === $row->cancel_token,
        );
    }

    public function test_requesting_an_email_change_rejects_a_wrong_password(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/email', [
            'password' => 'not-the-password',
            'new_email' => 'new@example.com',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('email_change_requests', ['member_id' => $member->id]);
    }

    public function test_requesting_an_email_change_rejects_the_current_address(): void
    {
        $member = Member::factory()->create(['email' => 'me@example.com']);

        $this->actingAs($member)->post('/member/config/email', [
            'password' => 'password',
            'new_email' => 'ME@example.com', // case-insensitive match to the current address
        ])->assertSessionHasErrors('new_email');

        $this->assertDatabaseMissing('email_change_requests', ['member_id' => $member->id]);
    }

    public function test_requesting_an_email_change_rejects_an_in_use_address(): void
    {
        Member::factory()->create(['email' => 'taken@example.com']);
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/email', [
            'password' => 'password',
            'new_email' => 'TAKEN@example.com', // case-insensitive collision
        ])->assertSessionHasErrors('new_email');

        $this->assertDatabaseMissing('email_change_requests', ['member_id' => $member->id]);
    }

    public function test_the_confirm_form_renders_for_a_valid_token(): void
    {
        $member = Member::factory()->create();
        $raw = str_repeat('a', 40);
        EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'new@example.com',
            'token' => hash('sha256', $raw), 'created_at' => now(),
        ]);

        // Reachable without auth, rendered as a pre-login page in the Classic shell.
        $this->get('/member/config/email/confirm/'.$raw)
            ->assertOk()
            ->assertSee('id="page_member_emailChangeConfirm"', false)
            ->assertSee('class="insecure_page"', false)
            ->assertSee('new@example.com')
            ->assertSee(route('member.config.email.confirm.submit', ['token' => $raw]), false);
        $this->assertSame('new@example.com', EmailChangeRequest::firstWhere('member_id', $member->id)?->new_email);
    }

    public function test_the_confirm_form_uses_the_secure_shell_for_the_logged_in_subject(): void
    {
        // The subject opening their own link while logged in gets the secure shell, matching the
        // logged-in nav/banner the Classic shell renders — so the OpenPNE 3 skin styles a coherent
        // secure_page + member-nav combination, not insecure_page + member nav.
        $member = Member::factory()->create();
        $raw = str_repeat('i', 40);
        EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'new@example.com',
            'token' => hash('sha256', $raw), 'created_at' => now(),
        ]);

        $this->actingAs($member)->get('/member/config/email/confirm/'.$raw)
            ->assertOk()
            ->assertSee('class="secure_page"', false)
            ->assertSee('new@example.com');
    }

    public function test_the_confirm_form_redirects_for_an_invalid_token(): void
    {
        $this->get('/member/config/email/confirm/'.str_repeat('z', 40))->assertRedirect(route('login'));
    }

    public function test_confirming_changes_the_email_and_logs_out(): void
    {
        $member = Member::factory()->create();
        $raw = str_repeat('b', 40);
        EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'changed@example.com',
            'token' => hash('sha256', $raw), 'created_at' => now(),
        ]);
        $this->actingAs($member);

        $this->post('/member/config/email/confirm/'.$raw)->assertRedirect(route('login'));

        $this->assertSame('changed@example.com', $member->fresh()->email);
        $this->assertDatabaseMissing('email_change_requests', ['member_id' => $member->id]);
        $this->get('/member/config')->assertRedirect('/login'); // logged out
    }

    public function test_confirming_rejects_an_invalid_token(): void
    {
        $this->post('/member/config/email/confirm/'.str_repeat('z', 40))->assertRedirect(route('login'));
    }

    public function test_confirming_while_logged_in_as_a_different_member_is_rejected(): void
    {
        // A different logged-in member is turned away, and the pending change, its token and their
        // session all stay intact.
        Member::factory()->create(['id' => 1]);
        $requester = Member::factory()->create();
        $other = Member::factory()->create();
        $raw = str_repeat('h', 40);
        EmailChangeRequest::create([
            'member_id' => $requester->id, 'new_email' => 'a-new@example.com',
            'token' => hash('sha256', $raw), 'created_at' => now(),
        ]);

        // Act as the other member ONCE, then chain the requests on that same session: a wrongful
        // logout/invalidate in the reject path would then surface on the final protected request,
        // rather than being masked by re-authenticating each call.
        $this->actingAs($other);

        $this->post('/member/config/email/confirm/'.$raw)->assertRedirect(route('home'));
        $this->assertNotSame('a-new@example.com', $requester->fresh()->email);
        $this->assertDatabaseHas('email_change_requests', ['member_id' => $requester->id]);

        $this->get('/member/config/email/confirm/'.$raw)->assertRedirect(route('home'));

        $this->get('/member/config')->assertOk();
    }

    public function test_confirming_rejects_an_address_claimed_since_the_request(): void
    {
        $member = Member::factory()->create();
        Member::factory()->create(['email' => 'grabbed@example.com']); // claimed after the request
        $raw = str_repeat('c', 40);
        EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'grabbed@example.com',
            'token' => hash('sha256', $raw), 'created_at' => now(),
        ]);

        $this->actingAs($member)->post('/member/config/email/confirm/'.$raw)->assertRedirect(route('login'));

        $this->assertDatabaseMissing('email_change_requests', ['member_id' => $member->id]); // dead token voided
        $this->assertNotSame('grabbed@example.com', $member->fresh()->email); // unchanged
    }

    public function test_the_pc_address_category_redirects_to_email(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/member/config?category=pcAddress')
            ->assertRedirect(route('member.config', ['category' => 'email']));
    }

    public function test_a_modern_email_change_request_redirects_to_the_bare_config(): void
    {
        Notification::fake();
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/member/config/email', [
            'password' => 'password',
            'new_email' => 'new@example.com',
        ])->assertRedirect(route('member.config'));

        $this->assertDatabaseHas('email_change_requests', ['member_id' => $member->id, 'new_email' => 'new@example.com']);
    }

    public function test_a_password_change_voids_a_pending_email_change(): void
    {
        $member = Member::factory()->create();
        EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'pending@example.com',
            'token' => hash('sha256', str_repeat('d', 40)), 'created_at' => now(),
        ]);

        $this->actingAs($member)->post('/member/config/password', [
            'current_password' => 'password',
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ])->assertRedirect(route('member.config', ['category' => 'password']));

        $this->assertDatabaseMissing('email_change_requests', ['member_id' => $member->id]);
    }

    public function test_confirming_an_email_change_rotates_remember_token_and_purges_other_sessions(): void
    {
        config()->set('session.driver', 'database');
        $member = Member::factory()->create(['remember_token' => 'old-remember-token']);
        DB::table('sessions')->insert([
            'id' => 'other-device-session', 'user_id' => $member->id,
            'ip_address' => '127.0.0.1', 'user_agent' => 'agent', 'payload' => 'x', 'last_activity' => 1700000000,
        ]);
        $raw = str_repeat('f', 40);
        EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'confirmed@example.com',
            'token' => hash('sha256', $raw), 'created_at' => now(),
        ]);

        $this->post('/member/config/email/confirm/'.$raw)->assertRedirect(route('login'));

        $fresh = $member->fresh();
        $this->assertSame('confirmed@example.com', $fresh->email);
        $this->assertNotSame('old-remember-token', $fresh->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device-session']);
    }

    public function test_an_expired_email_change_token_is_rejected(): void
    {
        // pendingEmailChange() rejects a token past its TTL; the confirm path leaves the dead row for
        // the scheduled prune rather than burning it, and members.email is untouched.
        $member = Member::factory()->create();
        $old = $member->email;
        $raw = str_repeat('g', 40);
        $ttl = (int) config('openpne.email_change.token_ttl_minutes');
        EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'expired@example.com',
            'token' => hash('sha256', $raw), 'created_at' => now()->subMinutes($ttl + 1),
        ]);

        $this->get('/member/config/email/confirm/'.$raw)->assertRedirect(route('login'));
        $this->post('/member/config/email/confirm/'.$raw)->assertRedirect(route('login'));

        $this->assertSame($old, $member->fresh()->email);
        $this->assertDatabaseHas('email_change_requests', ['member_id' => $member->id]); // left for prune
    }

    /** @return array{0: Member, 1: string} the member and the raw cancel token of a seeded pending change. */
    private function seedPendingWithCancelToken(string $rawCancel, string $newEmail = 'new@example.com'): array
    {
        $member = Member::factory()->create();
        EmailChangeRequest::create([
            'member_id' => $member->id,
            'new_email' => $newEmail,
            'token' => hash('sha256', str_repeat('c', 40)),
            'cancel_token' => hash('sha256', $rawCancel),
            'created_at' => now(),
        ]);

        return [$member, $rawCancel];
    }

    public function test_the_cancel_form_renders_and_does_not_void_on_the_get(): void
    {
        [$member, $raw] = $this->seedPendingWithCancelToken(str_repeat('a', 40));

        // Reachable without auth; the GET only renders, so a mail scanner / prefetch cannot cancel.
        $this->get('/member/config/email/cancel/'.$raw)
            ->assertOk()
            ->assertSee('id="page_member_emailChangeCancel"', false)
            ->assertSee('class="insecure_page"', false)
            ->assertSee('new@example.com')
            ->assertSee(route('member.config.email.cancel.submit', ['token' => $raw]), false);

        $this->assertDatabaseHas('email_change_requests', ['member_id' => $member->id]);
    }

    public function test_cancelling_voids_the_pending_change(): void
    {
        [$member, $raw] = $this->seedPendingWithCancelToken(str_repeat('b', 40));

        $this->post('/member/config/email/cancel/'.$raw)
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('email_change_requests', ['member_id' => $member->id]);
    }

    public function test_an_unknown_cancel_token_is_a_no_op_success(): void
    {
        // A gone/never-existed row is already not pending, so the POST is a harmless no-op success.
        $this->post('/member/config/email/cancel/'.str_repeat('z', 40))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');
        $this->get('/member/config/email/cancel/'.str_repeat('z', 40))->assertRedirect(route('login'));
    }

    public function test_an_expired_cancel_token_is_rejected(): void
    {
        $member = Member::factory()->create();
        $raw = str_repeat('e', 40);
        $ttl = (int) config('openpne.email_change.token_ttl_minutes');
        EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'new@example.com',
            'token' => hash('sha256', str_repeat('c', 40)),
            'cancel_token' => hash('sha256', $raw), 'created_at' => now()->subMinutes($ttl + 1),
        ]);

        $this->post('/member/config/email/cancel/'.$raw)->assertRedirect(route('login'));

        $this->assertDatabaseHas('email_change_requests', ['member_id' => $member->id]); // left for prune
    }

    public function test_a_confirm_race_with_a_cancel_does_not_change_the_email(): void
    {
        // A cancel between the controller's load and the action's commit must leave the identifier
        // unflipped.
        $member = Member::factory()->create(['email' => 'old@example.com']);
        $pending = EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'new@example.com',
            'token' => hash('sha256', str_repeat('r', 40)),
            'cancel_token' => hash('sha256', str_repeat('s', 40)), 'created_at' => now(),
        ]);

        // The controller's stale in-memory model; the row is gone by the time the action's transaction runs.
        EmailChangeRequest::whereKey($pending->getKey())->delete();

        $this->assertNull(app(ConfirmEmailChange::class)($pending));
        $this->assertSame('old@example.com', $member->fresh()->email);
    }

    public function test_a_confirm_token_does_not_work_on_the_cancel_route(): void
    {
        // The two tokens are distinct namespaces (separate columns): the confirm token, known to the
        // new-address holder, must not cancel; only the old-address cancel token does.
        $member = Member::factory()->create();
        $confirmRaw = str_repeat('h', 40);
        EmailChangeRequest::create([
            'member_id' => $member->id, 'new_email' => 'new@example.com',
            'token' => hash('sha256', $confirmRaw),
            'cancel_token' => hash('sha256', str_repeat('k', 40)), 'created_at' => now(),
        ]);

        $this->post('/member/config/email/cancel/'.$confirmRaw)->assertRedirect(route('login'));

        $this->assertDatabaseHas('email_change_requests', ['member_id' => $member->id]);
    }
}

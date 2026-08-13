<?php

namespace Tests\Feature\Profile;

use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Models\MemberImage;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Models\TimelinePost;
use App\Support\AvatarColor;
use App\Support\PreferenceKey;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MemberProfileRoutesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * OpenPNE 3 puts the own-page notice (and the add-%friend% box on someone else's page) in the
     * #Top slot, full-width above the columns — not inside the Center column.
     */
    public function test_the_profile_notice_box_renders_in_the_top_slot(): void
    {
        $owner = Member::factory()->create();

        $content = (string) $this->actingAs($owner)->get("/member/{$owner->getKey()}")->assertOk()->getContent();

        $top = strpos($content, '<div id="Top">');
        $box = strpos($content, 'id="informationAboutThisIsYourProfilePage"');
        $center = strpos($content, '<div id="Center">');
        $this->assertNotFalse($top);
        $this->assertNotFalse($box);
        $this->assertNotFalse($center);
        $this->assertGreaterThan($top, $box);
        $this->assertLessThan($center, $box, 'the notice box sits in #Top, above the Center column');
    }

    public function test_classic_renders_the_member_profile_with_visible_values(): void
    {
        $owner = Member::factory()->create(['name' => 'Owner']);
        $viewer = Member::factory()->create();
        $this->fieldFor($owner, Visibility::Members, 'a-members-value');

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('Owner')
            ->assertSee('a-members-value')
            ->assertSee('page_member_profile'); // body id from the route parity
    }

    public function test_modern_renders_the_inertia_component(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->fieldFor($owner, Visibility::Members, 'v');

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('member/show')
                ->where('profile.owner.id', $owner->getKey())
                ->where('profile.owner.avatarColor', null)
                ->has('profile.fields', 1)
            );
    }

    public function test_modern_owner_avatar_is_sized_for_the_80px_profile_header(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();
        MemberImage::factory()->create(['member_id' => $owner->getKey()]);
        $viewer = Member::factory()->create();

        // The header paints at 80px, so it takes 180 rather than the 120 the list avatars use.
        $expected = $owner->fresh()->avatar->file->thumbnailUrl(180, 180, square: true);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('profile.owner.avatarUrl', $expected));
    }

    public function test_modern_owner_carries_the_chosen_badge_color_as_hex(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();
        $owner->forceFill(['avatar_color' => AvatarColor::Green])->save();
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('member/show')
                ->where('profile.owner.avatarColor', '#15803d')
            );
    }

    public function test_private_value_is_hidden_from_a_non_friend(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->fieldFor($owner, Visibility::Private, 'secret-bio');

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertDontSee('secret-bio');
    }

    public function test_blocked_viewer_gets_404(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->fieldFor($owner, Visibility::Members, 'v');
        DB::table('member_blocks')->insert(['blocker_id' => $owner->getKey(), 'blocked_id' => $viewer->getKey()]);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")->assertNotFound();
    }

    public function test_guest_on_a_non_web_public_profile_is_redirected_to_login(): void
    {
        $owner = Member::factory()->create(); // default profile_visibility = Members

        $this->get("/member/{$owner->getKey()}")->assertRedirect('/login');
    }

    public function test_guest_can_view_a_web_public_profile(): void
    {
        $owner = Member::factory()->create(['name' => 'Public Owner', 'profile_visibility' => Visibility::Open]);
        $this->webField($owner, 'public-value');

        $this->get("/member/{$owner->getKey()}")->assertOk()->assertSee('public-value');
    }

    public function test_guest_does_not_see_non_web_public_fields_on_a_web_public_profile(): void
    {
        $owner = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $this->webField($owner, 'shown');
        $this->fieldFor($owner, Visibility::Members, 'hidden-value');

        $this->get("/member/{$owner->getKey()}")->assertOk()->assertSee('shown')->assertDontSee('hidden-value');
    }

    public function test_a_stranger_profile_offers_the_friend_request_entry(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('member/show')
                ->where('profile.friendStatus', 'none'));
    }

    public function test_a_friend_profile_has_no_request_entry(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        DB::table('friendships')->insert([
            ['member_id' => $owner->getKey(), 'friend_id' => $viewer->getKey()],
            ['member_id' => $viewer->getKey(), 'friend_id' => $owner->getKey()],
        ]);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('profile.friendStatus', 'friend'));
    }

    public function test_a_sent_request_shows_as_pending(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        DB::table('friend_requests')->insert([
            ['requester_id' => $viewer->getKey(), 'target_id' => $owner->getKey()],
        ]);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('profile.friendStatus', 'sent'));
    }

    public function test_a_received_request_is_flagged(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        DB::table('friend_requests')->insert([
            ['requester_id' => $owner->getKey(), 'target_id' => $viewer->getKey()],
        ]);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('profile.friendStatus', 'received'));
    }

    public function test_own_profile_has_no_friend_status(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();

        $this->actingAs($owner)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('profile.friendStatus', null));
    }

    public function test_a_viewer_who_blocks_the_owner_gets_no_friend_entry(): void
    {
        // The reverse direction (owner blocks viewer) 404s the whole page; this one still renders
        // the profile, and the friend-link form would reject the request — so no entry at all.
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        DB::table('member_blocks')->insert(['blocker_id' => $viewer->getKey(), 'blocked_id' => $owner->getKey()]);

        config(['openpne.surface_mode' => 'modern_default']);
        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('profile.friendStatus', null));

        config(['openpne.surface_mode' => 'classic_default']);
        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertDontSee('informationAboutThisIsYourProfilePage');
    }

    public function test_classic_stranger_profile_links_to_the_friend_request_form(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('informationAboutThisIsYourProfilePage')
            ->assertSee("/friend/link?id={$owner->getKey()}");
    }

    public function test_classic_own_profile_carries_the_own_page_notice(): void
    {
        $owner = Member::factory()->create();

        // OpenPNE 3 gave the own-page notice and the friend-request entry the same descriptionBox
        // (profileSuccess.php): the page URL to share, plus the way back to the profile editor.
        $this->actingAs($owner)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('informationAboutThisIsYourProfilePage')
            ->assertSee('This is how other members see your page.')
            ->assertSee(route('member.profile.show', $owner))
            ->assertSee(route('member.profile.edit'))
            ->assertDontSee('/friend/link?id=');
    }

    public function test_classic_friend_profile_omits_the_request_box(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        DB::table('friendships')->insert([
            ['member_id' => $owner->getKey(), 'friend_id' => $viewer->getKey()],
            ['member_id' => $viewer->getKey(), 'friend_id' => $owner->getKey()],
        ]);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertDontSee('informationAboutThisIsYourProfilePage');
    }

    public function test_guest_on_a_web_public_profile_gets_no_friend_entry(): void
    {
        $owner = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $this->webField($owner, 'shown');

        $this->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertDontSee('informationAboutThisIsYourProfilePage');
    }

    private function webField(Member $owner, string $value): void
    {
        $profile = Profile::factory()->create(['is_edit_public_flag' => true, 'is_public_web' => true]);
        MemberProfile::factory()->create([
            'member_id' => $owner->getKey(), 'profile_id' => $profile->getKey(),
            'value' => $value, 'visibility' => Visibility::Open,
        ]);
    }

    public function test_classic_shows_the_age_row_to_self(): void
    {
        $this->travelTo(Carbon::parse('2026-06-24'));
        $owner = Member::factory()->create();
        $this->giveBirthday($owner, '1990-06-23');

        $this->actingAs($owner)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('<th>Age</th>', false)
            ->assertSee('36 years old');
    }

    public function test_classic_hides_age_from_a_non_friend_by_default(): void
    {
        $this->travelTo(Carbon::parse('2026-06-24'));
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->giveBirthday($owner, '1990-06-23'); // AgeVisibility default = Private

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertDontSee('<th>Age</th>', false);
    }

    public function test_modern_payload_carries_the_gated_age(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $this->travelTo(Carbon::parse('2026-06-24'));
        $owner = Member::factory()->create();
        $owner->setPreference(PreferenceKey::AgeVisibility, Visibility::Members);
        $viewer = Member::factory()->create();
        $this->giveBirthday($owner, '1990-06-23');

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('member/show')
                ->where('profile.age', 36)
            );
    }

    public function test_a_guest_sees_a_web_public_age_on_a_web_public_profile(): void
    {
        // Two gates must both be open for a guest: the profile page itself is web-public, and the SNS
        // allows web-public age + the owner chose Open (OpenPNE 3 profile_page_public_flag × age_public_flag).
        $this->travelTo(Carbon::parse('2026-06-24'));
        $this->setSnsSetting(SnsSettingKey::AllowWebPublicAge, true);
        $owner = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $owner->setPreference(PreferenceKey::AgeVisibility, Visibility::Open);
        $this->giveBirthday($owner, '1990-06-23');

        $this->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('<th>Age</th>', false)
            ->assertSee('36 years old');
    }

    private function giveBirthday(Member $owner, string $date): void
    {
        $profile = Profile::factory()->create(['name' => 'op_preset_birthday', 'form_type' => 'date']);
        MemberProfile::factory()->create([
            'member_id' => $owner->getKey(), 'profile_id' => $profile->getKey(),
            'value' => $date, 'value_datetime' => $date.' 00:00:00',
        ]);
    }

    public function test_legacy_profile_aliases_redirect_to_the_canonical_url(): void
    {
        $viewer = Member::factory()->create();
        $other = Member::factory()->create();

        // /member/profile = the viewer's own profile; /member/profile/id/{id} = another member's.
        $this->actingAs($viewer)->get('/member/profile')->assertRedirect("/member/{$viewer->getKey()}");
        $this->actingAs($viewer)->get("/member/profile/id/{$other->getKey()}")->assertRedirect("/member/{$other->getKey()}");
        // OpenPNE 3's raw alias had a trailing splat; extra path segments still redirect, not 404.
        $this->actingAs($viewer)->get("/member/profile/id/{$other->getKey()}/extra")->assertRedirect("/member/{$other->getKey()}");
    }

    // --- Modern digest (bio promotion + viewer-scoped stats/previews) ---

    public function test_modern_promotes_the_self_introduction_to_bio(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->fieldFor($owner, Visibility::Members, 'a-regular-value');
        $this->selfIntroFor($owner, 'Hello, I am Owner', Visibility::Members);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('member/show')
                ->where('profile.bio', 'Hello, I am Owner')
                // The self-introduction is gone from the dl (only the regular field remains).
                ->has('profile.fields', 1)
                ->where('profile.fields.0.value', 'a-regular-value')
            );
    }

    public function test_a_private_self_introduction_is_not_promoted_for_a_non_friend(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->selfIntroFor($owner, 'private-bio-secret', Visibility::Private);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertDontSee('private-bio-secret');
    }

    public function test_digest_diary_count_is_scoped_to_the_viewer(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create(); // non-friend → Members clearance
        Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]);
        Diary::factory()->friends()->create(['member_id' => $owner->getKey()]);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('digest.stats.diaries', 1));
    }

    public function test_a_private_diary_title_is_absent_from_the_digest_payload(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        Diary::factory()->private()->create(['member_id' => $owner->getKey(), 'title' => 'my-private-diary-title']);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertDontSee('my-private-diary-title');
    }

    public function test_a_friend_sees_the_friends_only_diary_in_the_digest(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();
        $friend = Member::factory()->create();
        $this->makeFriends($owner, $friend);
        Diary::factory()->friends()->create(['member_id' => $owner->getKey(), 'title' => 'friends-only-entry']);

        $this->actingAs($friend)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('digest.stats.diaries', 1)
                ->where('digest.recentDiaries.0.title', 'friends-only-entry'));
    }

    public function test_the_digest_grids_match_the_stats_and_carry_deep_links(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        $this->makeFriends($owner, $friend);
        $group = Group::factory()->create();
        GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $owner->getKey()]);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('digest.stats.friends', 1)
                ->has('digest.friends', 1)
                ->where('digest.friends.0.id', $friend->getKey())
                ->where('digest.friends.0.href', "/member/{$friend->getKey()}")
                ->where('digest.stats.groups', 1)
                ->has('digest.groups', 1)
                ->where('digest.groups.0.id', $group->getKey())
                ->where('digest.groups.0.href', "/groups/{$group->getKey()}"));
    }

    public function test_activity_count_excludes_replies_and_invisible_posts(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create(); // non-friend → Members clearance
        $top = TimelinePost::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]);
        TimelinePost::factory()->replyTo($top)->create(['member_id' => $owner->getKey()]); // reply — excluded
        TimelinePost::factory()->friends()->create(['member_id' => $owner->getKey()]); // above clearance — hidden

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('digest.stats.activity', 1));
    }

    public function test_a_guest_receives_no_digest(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $this->webField($owner, 'public-value');

        $this->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('member/show')
                ->where('digest', null));
    }

    public function test_self_digest_includes_private_content(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();
        Diary::factory()->private()->create(['member_id' => $owner->getKey()]);
        TimelinePost::factory()->private()->create(['member_id' => $owner->getKey()]);

        $this->actingAs($owner)->get("/member/{$owner->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('digest.stats.diaries', 1)
                ->where('digest.stats.activity', 1));
    }

    private function fieldFor(Member $owner, Visibility $visibility, string $value): void
    {
        $profile = Profile::factory()->create(['is_edit_public_flag' => true]);
        MemberProfile::factory()->create([
            'member_id' => $owner->getKey(), 'profile_id' => $profile->getKey(),
            'value' => $value, 'visibility' => $visibility,
        ]);
    }

    private function selfIntroFor(Member $owner, string $value, Visibility $visibility = Visibility::Members): void
    {
        // op_preset_self_introduction is a per-install singleton (unique name); reuse it across members.
        $profile = Profile::query()->where('name', 'op_preset_self_introduction')->first()
            ?? Profile::factory()->preset('self_introduction')->create(['form_type' => 'textarea', 'is_edit_public_flag' => true]);
        MemberProfile::factory()->create([
            'member_id' => $owner->getKey(), 'profile_id' => $profile->getKey(),
            'value' => $value, 'visibility' => $visibility,
        ]);
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }
}

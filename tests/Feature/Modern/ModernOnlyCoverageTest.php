<?php

namespace Tests\Feature\Modern;

use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\EmailChangeRequest;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\MfaResetRequest;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * modern_only contract guard: a member browsing a Modern-only install must never land on a Classic
 * Blade page. Each member-facing canonical GET below is asserted to render Inertia under
 * surface_mode=modern_only.
 *
 * KNOWN_LEAKS is empty: no canonical GET renders Classic under modern_only anymore. The const and
 * its guards stay as the tripwire — a future Classic-only page fails the classification test until
 * it is Modernized (COVERED) or consciously allowlisted here.
 *
 * REDIRECTS_UNDER_MODERN are the OpenPNE 3 confirm pages (Modern confirms inline instead): under
 * modern_only a direct GET redirects to its context page rather than rendering a page.
 */
class ModernOnlyCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** Canonical GET route names that STILL render Classic under modern_only (kept empty). */
    private const KNOWN_LEAKS = [];

    /**
     * Not pages: what a shell's or a form's own script fetches — a Classic HTML fragment, the Modern
     * shell's JSON counts, the compose form's mention candidates. None renders a surface, so there is
     * nothing here for modern_only to get wrong.
     */
    private const FRAGMENTS = [
        'notifications.center',
        'unread.counts',
        'timeline.mention_candidates',
    ];

    /**
     * Parameterless OpenPNE 3 confirm pages: Classic renders a confirm Blade, Modern confirms inline,
     * so under modern_only a direct GET redirects to the community. Their parameterized siblings
     * (delete/unlink/purge confirms) are asserted case-by-case below, like the show pages.
     */
    private const REDIRECTS_UNDER_MODERN = [
        // Not confirms: OpenPNE 3 URLs whose Modern home is another canonical page.
        'friend.manage',
        'diary.comment.history',
        'community.join.show',
        'community.quit.show',
        'community.members.appoint.show',
        'community.members.demote.show',
        'community.members.drop.show',
        'community.members.transfer.show',
    ];

    /** Canonical GET route names asserted to render Inertia above (the two data-driven tests). */
    private const COVERED = [
        'home', 'dashboard',
        'diary.list', 'diary.list_friend', 'diary.search', 'diary.new',
        'timeline.index', 'timeline.new',
        'friend.list', 'friend.requests', 'friend.link.show',
        'block.list', 'block.add.show',
        'member.search', 'member.config', 'member.profile.edit', 'member.avatar.edit',
        'member.config.email.edit', 'member.config.password.edit', 'member.config.withdrawal.edit',
        'member.config.mfa.edit', 'member.config.notifications.edit',
        'community.search', 'community.list_mine', 'community.edit', 'community.members', 'community.members.pending',
        'community.recent',
        'message.index', 'message.index_compat', 'message.receive', 'message.send', 'message.draft', 'message.trash', 'message.compose',
        'member.invite',
        'notifications.index',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('openpne.surface_mode', 'modern_only');
    }

    /**
     * The core member navigation surface: parameterless canonical pages a member reaches by browsing.
     * Under modern_only every one must render Inertia (not Classic).
     */
    #[DataProvider('memberPages')]
    public function test_member_page_renders_modern_under_modern_only(string $uri): void
    {
        $member = Member::factory()->create();

        $this->followingRedirects()->actingAs($member)->get($uri)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page);
    }

    /** @return array<string, array{string}> */
    public static function memberPages(): array
    {
        return [
            'home (redirects to dashboard)' => ['/'],
            'dashboard' => ['/dashboard'],
            'diary list' => ['/diary/list'],
            'diary friend list' => ['/diary/listFriend'],
            'diary search' => ['/diary/search'],
            'diary new' => ['/diary/new'],
            'timeline' => ['/timeline'],
            'timeline new' => ['/timeline/new'],
            'friend list' => ['/friend/list'],
            'friend manage (redirects to the list)' => ['/friend/manage'],
            'friend requests' => ['/friend/requests'],
            'block list' => ['/block/list'],
            'member search' => ['/member/search'],
            'member config' => ['/member/config'],
            'member profile edit' => ['/member/edit/profile'],
            'member avatar' => ['/member/avatar'],
            'community search' => ['/community/search'],
            'community joined' => ['/community/joinList'],
            'community recent activity' => ['/community/recent'],
            'notification feed' => ['/notifications'],
            'community create form' => ['/community/edit'],
            'invite' => ['/invite'],
            'message index' => ['/message'],
            'message index alias' => ['/message/index'],
            'message inbox' => ['/message/receiveList'],
            'message sent' => ['/message/sendList'],
            'message drafts' => ['/message/draftList'],
            'message trash' => ['/message/dustList'],
        ];
    }

    /**
     * Pages that target another member via ?id= (the friend-link and block-add confirm forms). Both
     * go through respondWith, so under modern_only they must render Inertia.
     */
    public function test_member_target_pages_render_modern_under_modern_only(): void
    {
        [$viewer, $target] = Member::factory()->count(2)->create();

        $uris = [
            "/friend/link?id={$target->getKey()}",
            "/block/add?id={$target->getKey()}",
            "/message/sendToFriend?id={$target->getKey()}",
        ];
        foreach ($uris as $uri) {
            $this->actingAs($viewer)->get($uri)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page);
        }
    }

    /**
     * Community management pages target a community via ?id= and require the viewer to be its admin
     * (the member roster and the pending-approval queue). Both go through respondWith → Inertia.
     */
    public function test_community_management_pages_render_modern_under_modern_only(): void
    {
        $admin = Member::factory()->create();
        $community = Community::factory()->create();
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);

        foreach ([
            "/community/member/list?id={$community->getKey()}",
            "/community/member/pending?id={$community->getKey()}",
            "/community/member/manage/{$community->getKey()}",
        ] as $uri) {
            $this->actingAs($admin)->get($uri)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page);
        }
    }

    /**
     * The core parameterized canonical show pages (profile / diary / community / a hashtag's feed) —
     * the classification guard only covers parameterless routes, so these are asserted explicitly
     * (Codex). Under modern_only each must render its Inertia component.
     */
    public function test_parameterized_member_show_pages_render_modern_under_modern_only(): void
    {
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['visibility' => Visibility::Members]);
        $community = Community::factory()->create();

        $this->actingAs($viewer)->get('/timeline/tag/op4')
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('timeline/tag'));

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('member/show'));
        $this->actingAs($viewer)->get("/diary/{$diary->getKey()}")
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('diary/show'));
        $this->actingAs($viewer)->get("/community/{$community->getKey()}")
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('community/show'));
    }

    /**
     * Keeps the allowlists from going stale: every listed name must still be a registered route. A
     * page that is renamed/removed but left here — or a typo — fails, so the lists stay honest.
     */
    public function test_allowlisted_names_are_registered_routes(): void
    {
        foreach ([...self::KNOWN_LEAKS, ...self::REDIRECTS_UNDER_MODERN, ...self::FRAGMENTS] as $name) {
            $this->assertTrue(Route::has($name), "Allowlisted route [{$name}] no longer exists — remove it or fix the name.");
        }
    }

    /**
     * Keeps the allowlist honest (Codex): every parameterless member-facing canonical GET must be
     * classified — either page-covered above (COVERED) or an explicit KNOWN_LEAK. A newly added
     * Classic-only page therefore fails here until it is Modernized (added to COVERED) or consciously
     * allowlisted. Parameterized routes are covered case-by-case, not by this enumeration.
     */
    public function test_every_parameterless_member_canonical_get_is_classified(): void
    {
        $unclassified = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            $uri = $route->uri();

            if ($name === null) {
                continue;
            }
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if (str_contains($uri, '{') || str_starts_with($uri, 'admin')) {
                continue;
            }
            // Member-guarded only.
            $mw = $route->gatherMiddleware();
            $memberGuarded = (bool) array_filter($mw, fn ($m) => $m === 'auth' || str_contains((string) $m, 'Authenticate'));
            if (! $memberGuarded) {
                continue;
            }
            // Out of scope: Fortify/guest auth (separate modern_only concern) and Closure compat
            // redirects (aliases that only redirect, not surface-rendering pages).
            if (str_starts_with($name, 'password.') || in_array($name, ['login', 'register', 'register.sent', 'register.form', 'logout'], true)) {
                continue;
            }
            if ($route->getActionName() === 'Closure') {
                continue;
            }
            if (in_array($name, self::COVERED, true) || in_array($name, self::KNOWN_LEAKS, true)
                || in_array($name, self::REDIRECTS_UNDER_MODERN, true) || in_array($name, self::FRAGMENTS, true)) {
                continue;
            }

            $unclassified[] = "{$name} ({$uri})";
        }

        $this->assertSame([], $unclassified, 'Unclassified parameterless modern_only pages (add to COVERED once Modernized, to REDIRECTS_UNDER_MODERN for a confirm page, to FRAGMENTS if it is not a page, or to KNOWN_LEAKS): '.implode(', ', $unclassified));
    }

    /**
     * The confirm token landing from the email-change mail survives as a page under modern_only.
     * Asserts the VALID-token render, not only the invalid-token redirect — otherwise a Classic
     * render could hide behind the redirect.
     */
    public function test_valid_token_email_change_confirm_renders_modern_under_modern_only(): void
    {
        $member = Member::factory()->create();
        $token = str_repeat('a', 40);
        EmailChangeRequest::create([
            'member_id' => $member->getKey(),
            'new_email' => 'new@example.com',
            'token' => hash('sha256', $token),
            'created_at' => now(),
        ]);

        $this->actingAs($member)->get("/member/config/email/confirm/{$token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('auth/email-change-confirm')
                ->where('newEmail', 'new@example.com'));
    }

    /**
     * The cancel token landing is guest-reachable (the token proves control of the old address), so
     * it is asserted without a login — its own test, since actingAs in a shared test would persist.
     */
    public function test_email_change_cancel_renders_modern_for_a_guest_under_modern_only(): void
    {
        $member = Member::factory()->create();
        $cancelToken = str_repeat('b', 40);
        EmailChangeRequest::create([
            'member_id' => $member->getKey(),
            'new_email' => 'new@example.com',
            'token' => hash('sha256', str_repeat('a', 40)),
            'cancel_token' => hash('sha256', $cancelToken),
            'created_at' => now(),
        ]);

        $this->get("/member/config/email/cancel/{$cancelToken}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('auth/email-change-cancel')
                ->where('newEmail', 'new@example.com'));
    }

    /**
     * The admin-issued MFA reset link landing survives as a page under modern_only. Asserts the
     * VALID-token render (a live factor, so it does not redirect out), not only the invalid-token
     * redirect — otherwise a Classic render could hide behind the redirect. Guest-reachable, so no login.
     */
    public function test_valid_token_mfa_reset_renders_modern_under_modern_only(): void
    {
        $member = Member::factory()->create();
        app(EnableTwoFactorAuthentication::class)($member, force: true);
        $member->forceFill(['two_factor_confirmed_at' => now()])->save();

        $token = str_repeat('c', 40);
        MfaResetRequest::create([
            'member_id' => $member->getKey(),
            'token' => hash('sha256', $token),
            'created_at' => now(),
        ]);

        $this->get("/member/mfa/reset/{$token}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('auth/mfa-reset')
                ->where('token', $token));
    }

    /**
     * The parameterless confirm pages: redirect under modern_only (Modern confirms inline), still a
     * Classic confirm page under a coexistence mode.
     */
    public function test_join_and_quit_confirms_redirect_under_modern_only_and_render_under_classic(): void
    {
        $member = Member::factory()->create();
        $toJoin = Community::factory()->create();
        $toQuit = Community::factory()->create();
        CommunityMember::factory()->create(['community_id' => $toQuit->getKey(), 'member_id' => $member->getKey()]);

        $this->actingAs($member)->get("/community/join?id={$toJoin->getKey()}")
            ->assertRedirect(route('community.show', $toJoin));
        $this->actingAs($member)->get("/community/quit?id={$toQuit->getKey()}")
            ->assertRedirect(route('community.show', $toQuit));

        config()->set('openpne.surface_mode', 'classic_default');

        $this->actingAs($member)->get("/community/join?id={$toJoin->getKey()}")
            ->assertOk()->assertSee('id="page_community_join"', false);
        $this->actingAs($member)->get("/community/quit?id={$toQuit->getKey()}")
            ->assertOk()->assertSee('id="page_community_quit"', false);
    }

    /**
     * The parameterized OpenPNE 3 confirm pages (delete/unlink/purge): under modern_only each
     * redirects to its context page — the screen whose inline dialog replaces the confirm.
     */
    public function test_parameterized_confirm_pages_redirect_under_modern_only(): void
    {
        $viewer = Member::factory()->create();

        $diary = Diary::factory()->create(['member_id' => $viewer->getKey()]);
        $this->actingAs($viewer)->get(route('diary.delete.show', $diary))
            ->assertRedirect(route('diary.show', $diary));

        $comment = DiaryComment::factory()->create(['diary_id' => $diary->getKey(), 'member_id' => $viewer->getKey()]);
        $this->actingAs($viewer)->get(route('diary.comment.delete.show', ['comment' => $comment->getKey()]))
            ->assertRedirect(route('diary.show', $diary));

        $post = TimelinePost::factory()->create(['member_id' => $viewer->getKey()]);
        $this->actingAs($viewer)->get(route('timeline.delete.show', ['timelinePost' => $post->getKey()]))
            ->assertRedirect(route('timeline.show', ['timelinePost' => $post->getKey()]));

        $community = Community::factory()->create();
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey(), 'member_id' => $viewer->getKey()]);
        $this->actingAs($viewer)->get(route('community.delete.show', $community))
            ->assertRedirect(route('community.show', $community));

        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey(), 'member_id' => $viewer->getKey()]);
        $this->actingAs($viewer)->get(route('communityTopic.delete.show', $topic))
            ->assertRedirect(route('communityTopic.show', $topic));

        $topicComment = CommunityTopicComment::factory()->create(['community_topic_id' => $topic->getKey(), 'member_id' => $viewer->getKey()]);
        $this->actingAs($viewer)->get(route('communityTopic.comment.delete.show', ['comment' => $topicComment->getKey()]))
            ->assertRedirect(route('communityTopic.show', $topic));

        $event = CommunityEvent::factory()->create(['community_id' => $community->getKey(), 'member_id' => $viewer->getKey()]);
        $this->actingAs($viewer)->get(route('communityEvent.delete.show', $event))
            ->assertRedirect(route('communityEvent.show', $event));

        $eventComment = CommunityEventComment::factory()->create(['community_event_id' => $event->getKey(), 'member_id' => $viewer->getKey()]);
        $this->actingAs($viewer)->get(route('communityEvent.comment.delete.show', ['comment' => $eventComment->getKey()]))
            ->assertRedirect(route('communityEvent.show', $event));

        $friend = Member::factory()->create();
        DB::table('friendships')->insert([
            ['member_id' => $viewer->getKey(), 'friend_id' => $friend->getKey()],
            ['member_id' => $friend->getKey(), 'friend_id' => $viewer->getKey()],
        ]);
        $this->actingAs($viewer)->get(route('friend.unlink.show', ['member' => $friend->getKey()]))
            ->assertRedirect(route('member.profile.show', ['member' => $friend->getKey()]));

        $message = Message::factory()->create(['sender_id' => $friend->getKey()]);
        MessageRecipient::factory()->create([
            'message_id' => $message->getKey(),
            'recipient_id' => $viewer->getKey(),
            'recipient_deleted_at' => now(),
        ]);
        $this->actingAs($viewer)->get(route('message.trash.purge.confirm', ['message' => $message->getKey()]))
            ->assertRedirect(route('message.trash.show', ['message' => $message->getKey()]));
    }
}

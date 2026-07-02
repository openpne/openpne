<?php

namespace Tests\Feature\Modern;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Diary;
use App\Models\EmailChangeRequest;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * modern_only contract guard: a member browsing a Modern-only tenant must never land on a Classic
 * Blade page. Each member-facing canonical GET below is asserted to render Inertia under
 * tenant_mode=modern_only.
 *
 * KNOWN_LEAKS are the canonical GETs that still fall back to Classic — the OpenPNE 3 delete/join/quit
 * confirm pages (Modern confirms inline instead) plus the email-change confirm. Each is Modernized or
 * blocked as its Modern surface lands, emptying this list.
 */
class ModernOnlyCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** Canonical GET route names that STILL render Classic under modern_only (to be closed). */
    private const KNOWN_LEAKS = [
        'community.join.show',                  // CommunityController::showJoin        -> community.join
        'community.quit.show',                  // CommunityController::showQuit        -> community.quit
        'community.delete.show',                // CommunityController::showDelete      -> community.delete
        'communityEvent.delete.show',           // CommunityEventController::showDelete
        'communityEvent.comment.delete.show',   // CommunityEventCommentController::showDelete
        'communityTopic.delete.show',           // CommunityTopicController::showDelete
        'communityTopic.comment.delete.show',   // CommunityTopicCommentController::showDelete
        'message.trash.purge.confirm',          // MessageController::purgeConfirm      -> message.purge_confirm
        'member.config.email.confirm',          // MemberConfigController::confirmEmailForm -> member.email-change-confirm
    ];

    /** Canonical GET route names asserted to render Inertia above (the two data-driven tests). */
    private const COVERED = [
        'home', 'dashboard',
        'diary.list', 'diary.list_friend', 'diary.search', 'diary.new',
        'timeline.index', 'timeline.new',
        'friend.list', 'friend.manage', 'friend.link.show',
        'block.list', 'block.add.show',
        'member.search', 'member.config', 'member.profile.edit', 'member.avatar.edit',
        'community.search', 'community.list_mine', 'community.edit', 'community.members', 'community.members.pending',
        'message.index', 'message.receive', 'message.send', 'message.draft', 'message.trash', 'message.compose',
        'member.invite',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('openpne.tenant_mode', 'modern_only');
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
            'friend manage' => ['/friend/manage'],
            'block list' => ['/block/list'],
            'member search' => ['/member/search'],
            'member config' => ['/member/config'],
            'member profile edit' => ['/member/edit/profile'],
            'member avatar' => ['/member/avatar'],
            'community search' => ['/community/search'],
            'community joined' => ['/community/joinList'],
            'community create form' => ['/community/edit'],
            'invite' => ['/invite'],
            'message index' => ['/message'],
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

        foreach (["/community/member/list?id={$community->getKey()}", "/community/member/pending?id={$community->getKey()}"] as $uri) {
            $this->actingAs($admin)->get($uri)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page);
        }
    }

    /**
     * The core parameterized canonical show pages (profile / diary / community) — the classification
     * guard only covers parameterless routes, so these are asserted explicitly (Codex). Under
     * modern_only each must render its Inertia component.
     */
    public function test_parameterized_member_show_pages_render_modern_under_modern_only(): void
    {
        $viewer = Member::factory()->create();
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['visibility' => Visibility::Members]);
        $community = Community::factory()->create();

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('member/show'));
        $this->actingAs($viewer)->get("/diary/{$diary->getKey()}")
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('diary/show'));
        $this->actingAs($viewer)->get("/community/{$community->getKey()}")
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('community/show'));
    }

    /**
     * Keeps KNOWN_LEAKS from going stale: every allowlisted name must still be a registered route. A
     * leak that is Modernized/renamed but left here — or a typo — fails, so shrinking the allowlist to
     * zero cannot be silently forgotten.
     */
    public function test_known_leaks_are_registered_routes(): void
    {
        foreach (self::KNOWN_LEAKS as $name) {
            $this->assertTrue(Route::has($name), "KNOWN_LEAKS route [{$name}] no longer exists — remove it (Modernized?) or fix the name.");
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
            if (str_contains($name, '.modern.') || str_contains($uri, '{') || str_starts_with($uri, 'admin')) {
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
            if (in_array($name, self::COVERED, true) || in_array($name, self::KNOWN_LEAKS, true)) {
                continue;
            }

            $unclassified[] = "{$name} ({$uri})";
        }

        $this->assertSame([], $unclassified, 'Unclassified parameterless modern_only pages (add to COVERED once Modernized, or to KNOWN_LEAKS): '.implode(', ', $unclassified));
    }

    /**
     * Asserts the VALID-token render, not only the invalid-token redirect — otherwise the leak hides
     * behind the redirect. Today the valid-token confirm renders a Classic Blade (allowlisted); it
     * turns Inertia and drops out of KNOWN_LEAKS once Modernized.
     */
    public function test_valid_token_email_change_confirm_is_a_known_classic_leak(): void
    {
        $this->assertContains('member.config.email.confirm', self::KNOWN_LEAKS);

        $member = Member::factory()->create();
        $raw = str_repeat('a', 40);
        EmailChangeRequest::create([
            'member_id' => $member->getKey(),
            'new_email' => 'new@example.com',
            'token' => hash('sha256', $raw),
            'created_at' => now(),
        ]);

        $response = $this->actingAs($member)->get("/member/config/email/confirm/{$raw}");

        // Renders 200 but as Classic (the OP3 body id proves it is Blade, not Inertia) — the leak to
        // close by Modernizing this confirm.
        $response->assertOk();
        $this->assertStringContainsString('page_member_emailChangeConfirm', $response->getContent());
    }
}

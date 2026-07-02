<?php

namespace Tests\Feature\Modern;

use App\Models\EmailChangeRequest;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * modern_only contract guard: a member browsing a Modern-only tenant must never land on a Classic
 * Blade page. Each member-facing canonical GET below is asserted to render Inertia under
 * tenant_mode=modern_only.
 *
 * KNOWN_LEAKS are the canonical GETs that still fall back to Classic — the OpenPNE 3 delete/join/quit
 * confirm pages (Modern confirms inline instead) plus the email-change confirm. The Modern UX
 * campaign Modernizes or blocks them in phase 3 and empties this list. See
 * worklog/current/modern-ux-campaign.md.
 */
class ModernOnlyCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** Canonical GET route names that STILL render Classic under modern_only (to close in phase 3). */
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

        foreach (["/friend/link?id={$target->getKey()}", "/block/add?id={$target->getKey()}"] as $uri) {
            $this->actingAs($viewer)->get($uri)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page);
        }
    }

    /**
     * Codex asked to assert the VALID-token render, not only the invalid-token redirect — otherwise the
     * leak hides behind the redirect. Today the valid-token confirm renders a Classic Blade
     * (allowlisted); phase 3 turns it Inertia and drops it from KNOWN_LEAKS.
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

        // Renders 200 but as Classic (the OP3 body id proves it is Blade, not Inertia) — the leak the
        // campaign closes in phase 3.
        $response->assertOk();
        $this->assertStringContainsString('page_member_emailChangeConfirm', $response->getContent());
    }
}

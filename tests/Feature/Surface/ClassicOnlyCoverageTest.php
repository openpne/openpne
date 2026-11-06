<?php

namespace Tests\Feature\Surface;

use App\Models\Group;
use App\Models\Member;
use App\Support\ClassicOnlyResponseFactory;
use App\Support\Look;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\ResponseFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every member-facing canonical GET is asserted to render Classic or answer 404 under
 * surface_mode=classic_only, and the classification test fails on a parameterless one that is in
 * neither list.
 */
class ClassicOnlyCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** Canonical GET route names with no Classic twin: 404 under classic_only, by the Inertia render gate. */
    private const MODERN_ONLY = [
        'dashboard', 'home.issues',
        'group.recent',
        'message.chat.index', 'message.chat.new', 'message.chat.withdrawn',
        'member.config.email.edit', 'member.config.password.edit', 'member.config.withdrawal.edit',
        'member.config.mfa.edit', 'member.config.passkeys.edit', 'member.config.notifications.edit', 'member.config.ai',
        'member.config.look.edit',
    ];

    /** Same set as ModernOnlyCoverageTest: fetched by a shell's or a form's own script, not pages. */
    private const FRAGMENTS = [
        'notifications.center', 'notifications.center.counts',
        'unread.counts',
        'timeline.mention_candidates',
        'timeline.index.rows', 'timeline.member.rows', 'timeline.tag.rows',
        'group.talk.mention_candidates',
        'message.chat.recipients',
        'message.chat.withdrawn.messages',
        'member.config.passkeys.options',
    ];

    /**
     * Parameterless canonical GET route names asserted to render Classic: by classicPages(), or by
     * test_query_parameterized_pages_render_classic() for the ones that need an ?id=.
     */
    private const CLASSIC = [
        'home',
        'diary.list', 'diary.list_friend', 'diary.search', 'diary.new', 'diary.comment.history',
        'timeline.index', 'timeline.new',
        'friend.list', 'friend.manage', 'friend.requests', 'friend.link.show',
        'block.list', 'block.add.show',
        'member.search', 'member.config', 'member.profile.edit', 'member.avatar.edit',
        'group.search', 'group.list_mine', 'group.edit',
        'group.topics.recent', 'group.events.recent',
        'message.index', 'message.index_compat', 'message.receive', 'message.send', 'message.draft', 'message.trash', 'message.compose',
        'member.invite',
        'notifications.index',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('openpne.surface_mode', 'classic_only');
    }

    private static function assertClassic(TestResponse $response): void
    {
        $response->assertOk();
        $response->assertDontSee('data-page=', false);
        // The Classic shell; an OpenPNE 4-native page carries an empty body id.
        $response->assertSee('<div id="Body">', false);
    }

    #[DataProvider('classicPages')]
    public function test_member_page_renders_classic_under_classic_only(string $uri): void
    {
        $member = Member::factory()->create();

        self::assertClassic($this->followingRedirects()->actingAs($member)->get($uri));
    }

    /** @return array<string, array{string}> */
    public static function classicPages(): array
    {
        return [
            'home' => ['/'],
            'diary list' => ['/diary/list'],
            'diary friend list' => ['/diary/listFriend'],
            'diary search' => ['/diary/search'],
            'diary new' => ['/diary/new'],
            'diary comment history' => ['/diary/comment/history'],
            'timeline' => ['/timeline'],
            'timeline new' => ['/timeline/new'],
            'friend list' => ['/friend/list'],
            'friend manage' => ['/friend/manage'],
            'friend requests' => ['/friend/requests'],
            'block list' => ['/block/list'],
            'member search' => ['/member/search'],
            'member config' => ['/member/config'],
            'member profile edit' => ['/member/edit/profile'],
            'member avatar' => ['/member/avatar'],
            'community search' => ['/groups'],
            'community joined' => ['/groups/mine'],
            'recent topics' => ['/topics'],
            'recent events' => ['/events'],
            'notification feed' => ['/notifications'],
            'community create form' => ['/groups/edit'],
            'invite' => ['/invite'],
            'message index' => ['/message'],
            'message index alias' => ['/message/index'],
            'message inbox' => ['/message/receiveList'],
            'message sent' => ['/message/sendList'],
            'message drafts' => ['/message/draftList'],
            'message trash' => ['/message/dustList'],
        ];
    }

    #[DataProvider('modernOnlyPages')]
    public function test_modern_only_page_answers_not_found_under_classic_only(string $uri): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get($uri)
            ->assertNotFound()
            ->assertSee('id="page_default_error"', false);
    }

    /** @return array<string, array{string}> */
    public static function modernOnlyPages(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'back issues' => ['/home/issues'],
            'community recent activity' => ['/groups/recent'],
            'conversation list' => ['/messages'],
            'new message picker' => ['/messages/new'],
            'withdrawn conversation' => ['/messages/withdrawn'],
            'email edit' => ['/member/config/email'],
            'password edit' => ['/member/config/password'],
            'withdrawal edit' => ['/member/config/withdrawal'],
            'mfa edit' => ['/member/config/mfa'],
            'passkeys' => ['/member/config/passkeys'],
            'notifications edit' => ['/member/config/notifications'],
            'ai accounts' => ['/member/config/ai'],
        ];
    }

    /** Parameterless in the route table but 404 without their ?id=, so outside classicPages(). */
    public function test_query_parameterized_pages_render_classic(): void
    {
        [$viewer, $target] = Member::factory()->count(2)->create();

        foreach ([
            "/friend/link?id={$target->getKey()}",
            "/block/add?id={$target->getKey()}",
            "/message/sendToFriend?id={$target->getKey()}",
        ] as $uri) {
            self::assertClassic($this->actingAs($viewer)->get($uri));
        }
    }

    /** With one look the picker redirects to the settings page; with two it is a Modern-only page. */
    public function test_the_look_picker_is_not_there_once_it_has_a_choice(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get('/member/config/look')->assertRedirect(route('member.config'));

        $this->setSnsSetting(SnsSettingKey::SelectableLooks, [Look::Unified]);
        $this->actingAs($viewer)->get('/member/config/look')->assertNotFound();
    }

    /** The classification guard covers only parameterless routes, so representative parameterized ones are asserted here. */
    public function test_parameterized_pages_follow_the_same_split(): void
    {
        $viewer = Member::factory()->create();
        $other = Member::factory()->create();
        $group = Group::factory()->create();
        $aiAccount = Member::factory()->aiAccount($viewer)->create();

        $this->actingAs($viewer)->get('/home/2026/08')->assertNotFound();
        $this->actingAs($viewer)->get('/home/2026/08/27')->assertNotFound();
        $this->actingAs($viewer)->get("/messages/{$other->getKey()}")->assertNotFound();
        $this->actingAs($viewer)->get("/groups/{$group->getKey()}/talk")->assertNotFound();

        self::assertClassic($this->actingAs($viewer)->get("/member/config/ai/{$aiAccount->getKey()}"));
        self::assertClassic($this->actingAs($viewer)->get("/groups/{$group->getKey()}"));
    }

    public function test_the_render_gate_is_the_bound_inertia_factory(): void
    {
        $this->assertInstanceOf(ClassicOnlyResponseFactory::class, app(ResponseFactory::class));
    }

    /** A stale Modern tab's navigation is reloaded into Classic rather than answered with a page. */
    public function test_an_inertia_navigation_is_handed_off_with_a_location_reload(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/friend/list?page=2', ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url('/friend/list?page=2'))
            ->assertHeader('Vary', 'X-Inertia');
    }

    /** A route middleware's abort (here the talk unit's gate) never reaches HandleInertiaRequests, so the error renderer hands off too. */
    public function test_an_inertia_navigation_refused_by_a_route_middleware_is_handed_off_as_well(): void
    {
        $member = Member::factory()->create();
        $group = Group::factory()->create();

        $this->actingAs($member)->get("/groups/{$group->getKey()}/talk", ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url("/groups/{$group->getKey()}/talk"));
        $this->actingAs($member)->get('/diary/999999', ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url('/diary/999999'));
    }

    /** A stale tab's POST runs (its author meant it) and the redirect it answers with is what gets handed off. */
    public function test_an_inertia_post_runs_and_its_landing_is_handed_off(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/logout', [], ['X-Inertia' => 'true'])->assertRedirect();
        $this->assertGuest('member');

        $this->get('/login', ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url('/login'));
    }

    public function test_allowlisted_names_are_registered_routes(): void
    {
        foreach ([...self::CLASSIC, ...self::MODERN_ONLY, ...self::FRAGMENTS] as $name) {
            $this->assertTrue(Route::has($name), "Listed route [{$name}] no longer exists — remove it or fix the name.");
        }
    }

    /** Every parameterless member-facing canonical GET must be in one list, so a new Modern-only page is classified consciously. */
    public function test_every_parameterless_member_canonical_get_is_classified(): void
    {
        $unclassified = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            $uri = $route->uri();

            if ($name === null || ! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if (str_contains($uri, '{') || str_starts_with($uri, 'admin')) {
                continue;
            }
            $mw = $route->gatherMiddleware();
            $memberGuarded = (bool) array_filter($mw, fn ($m) => $m === 'auth' || str_contains((string) $m, 'Authenticate'));
            if (! $memberGuarded) {
                continue;
            }
            if (str_starts_with($name, 'password.') || in_array($name, ['login', 'register', 'register.sent', 'register.form', 'logout', 'passport.authorizations.authorize'], true)) {
                continue;
            }
            if ($route->getActionName() === 'Closure') {
                continue;
            }
            if (in_array($name, self::CLASSIC, true) || in_array($name, self::MODERN_ONLY, true) || in_array($name, self::FRAGMENTS, true)) {
                continue;
            }

            $unclassified[] = "{$name} ({$uri})";
        }

        $this->assertSame([], $unclassified, 'Unclassified parameterless classic_only pages (add to CLASSIC if it renders Classic, to MODERN_ONLY if it has no Classic twin, or to FRAGMENTS if it is not a page): '.implode(', ', $unclassified));
    }
}

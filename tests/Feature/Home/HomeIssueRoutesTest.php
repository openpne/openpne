<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Features\Home\Data\HomeIssueDay;
use App\Features\Home\HomeIssueSection;
use App\Models\Diary;
use App\Models\HomeIssue;
use App\Models\HomeIssueItem;
use App\Models\Member;
use App\Support\Look;
use App\Support\SnsSettingKey;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The front page and the two issue URLs it is the head of. `/home/issues` and `/home/{y}/{m}/{d}`
 * are Modern-only, like `/groups/recent`, while `/` resolves by surface
 * (docs/internals/home-issues.md, "Routes").
 */
class HomeIssueRoutesTest extends TestCase
{
    use RefreshDatabase;

    /** A 06:00 publication, whose issue covers the day that just ended (HomeIssueDay). */
    private const NOW = '2026-08-28 06:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);
        config(['openpne.surface_mode' => 'modern_only']);
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->publish();

        $this->get('/home/issues')->assertRedirect('/login');
        $this->get('/home/2026/08/27')->assertRedirect('/login');
    }

    #[DataProvider('surfaceModes')]
    public function test_a_member_reads_both_pages_on_any_surface(string $mode): void
    {
        config(['openpne.surface_mode' => $mode]);
        $this->publish();

        $this->actingAs(Member::factory()->create())->get('/home/issues')
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('home/issues'));

        $this->actingAs(Member::factory()->create())->get('/home/2026/08/27')
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('home/archive'));
    }

    /** @return array<string, array{string}> */
    public static function surfaceModes(): array
    {
        return [
            'modern only' => ['modern_only'],
            'modern default' => ['modern_default'],
            // Classic is the site's OpenPNE 3 surface, and an issue has no Classic twin to fall back
            // to — the same answer /groups/recent gives.
            'classic default' => ['classic_default'],
        ];
    }

    /** The unified look swaps the page a screen draws, never the route or the payload. */
    public function test_the_unified_look_still_renders_the_issue_page(): void
    {
        $this->publish();
        $this->setSnsSetting(SnsSettingKey::DefaultLook, Look::Unified);
        $this->freshRequestState();

        $this->actingAs(Member::factory()->create())->get('/home/2026/08/27')
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('home/archive'));
    }

    /** A month or a day may be written either way; the URL the site links is the padded one. */
    public function test_a_date_resolves_padded_or_not(): void
    {
        $this->publish();
        $member = Member::factory()->create();

        foreach (['/home/2026/08/27', '/home/2026/8/27'] as $uri) {
            $this->actingAs($member)->get($uri)
                ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('issue.href', '/home/2026/08/27'));
        }
    }

    public function test_a_day_with_no_issue_is_not_a_page(): void
    {
        $this->publish();

        $this->actingAs(Member::factory()->create())->get('/home/2026/08/26')->assertNotFound();
    }

    /**
     * February 30th is March 2nd to a date constructor, and the issue published on the 2nd answers to
     * its own URL only.
     */
    public function test_a_day_that_does_not_exist_is_not_a_page(): void
    {
        $this->publishOn('2026-03-02');
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/home/2026/02/30')->assertNotFound();
        $this->actingAs($member)->get('/home/2026/03/02')->assertOk();
    }

    public function test_a_month_or_day_out_of_range_is_not_a_route_at_all(): void
    {
        $member = Member::factory()->create();

        foreach (['/home/2026/13/01', '/home/2026/01/32', '/home/0000/01/01'] as $uri) {
            $this->actingAs($member)->get($uri)->assertNotFound();
        }
    }

    /** The literal must win over the dated wildcard, whatever order the router walks. */
    public function test_the_archive_index_is_not_swallowed_by_the_dated_route(): void
    {
        $this->actingAs(Member::factory()->create())->get('/home/issues')
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('home/issues'));
    }

    /** Through the route rather than against the query: the neighbours are what the page is handed. */
    public function test_the_pager_points_at_the_issues_either_side(): void
    {
        $this->publishOn('2026-08-25');
        $this->publishOn('2026-08-26');
        $this->publishOn('2026-08-27');

        $member = Member::factory()->create();

        $this->actingAs($member)->get('/home/2026/08/26')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('prev.href', '/home/2026/08/25')
                ->where('next.href', '/home/2026/08/27'));

        $this->actingAs($member)->get('/home/2026/08/25')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('prev', null)
                ->where('next.href', '/home/2026/08/26'));

        $this->actingAs($member)->get('/home/2026/08/27')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('prev.href', '/home/2026/08/26')
                ->where('next', null));
    }

    /** The front page is the newest issue there is, under either Modern surface. */
    #[DataProvider('modernSurfaceModes')]
    public function test_the_root_is_the_latest_issue(string $mode): void
    {
        config(['openpne.surface_mode' => $mode]);
        $issue = $this->publish();

        $this->actingAs(Member::factory()->create())->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('home/issue')
                ->where('issue.number', (int) $issue->number)
                ->where('issue.isCurrent', true));
    }

    /** @return array<string, array{string}> */
    public static function modernSurfaceModes(): array
    {
        return [
            'modern only' => ['modern_only'],
            'modern default' => ['modern_default'],
        ];
    }

    public function test_the_unified_look_still_renders_the_front_page(): void
    {
        $this->publish();
        $this->setSnsSetting(SnsSettingKey::DefaultLook, Look::Unified);
        $this->freshRequestState();

        $this->actingAs(Member::factory()->create())->get('/')
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('home/issue'));
    }

    /** Backwards only: the newest issue is what the front page IS, so nothing stands forward of it. */
    public function test_the_front_page_pager_only_goes_back(): void
    {
        $this->publishOn('2026-08-26');
        $this->publishOn('2026-08-27');

        $this->actingAs(Member::factory()->create())->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('prev.href', '/home/2026/08/26')
                ->where('next', null));
    }

    /**
     * A day that produced nothing publishes no issue, so the latest one is regularly not today's —
     * and a reader arriving the morning after must still find it, dated as the day it covers.
     */
    public function test_the_latest_issue_stands_after_its_own_day_has_passed(): void
    {
        $issue = $this->publish();
        // Past the next boundary: the 28th's issue was due at 06:00 and nothing came out.
        Carbon::setTestNow('2026-08-29 07:00:00');

        $this->actingAs(Member::factory()->create())->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('issue.number', (int) $issue->number)
                ->where('issue.isCurrent', false));
    }

    public function test_a_site_that_has_published_nothing_still_has_a_front_page(): void
    {
        $this->actingAs(Member::factory()->create())->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('home/issue')->where('issue', null));
    }

    /** The cutover is the Modern arm's alone: Classic still serves the OpenPNE 3 gadget home. */
    public function test_the_classic_root_is_still_the_gadget_home(): void
    {
        config(['openpne.surface_mode' => 'classic_default']);
        $this->publish();

        $this->actingAs(Member::factory()->create())->get('/')
            ->assertOk()
            ->assertSee('id="page_member_home"', false);
    }

    public function test_the_member_alias_still_lands_on_the_front_page(): void
    {
        $this->actingAs(Member::factory()->create())->get('/member')->assertRedirect('/');
    }

    /** The Modern arm serves member content too, so `auth.session` is pinned on it as well as on Classic. */
    public function test_a_stale_session_is_ended_on_the_modern_front_page(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/')->assertOk();
        // Another device changes the password; this session's stored hash is now stale.
        $member->forceFill(['password' => Hash::make('changed-elsewhere')])->save();

        $this->get('/')->assertRedirect('/login');
    }

    /** The issue the frozen clock has just published: the day before it, with one story in it. */
    private function publish(): HomeIssue
    {
        return $this->publishOn(CarbonImmutable::parse(self::NOW)->subDay()->toDateString());
    }

    /** One issue covering the day $date, over that day's own window, with one story in it. */
    private function publishOn(string $date): HomeIssue
    {
        $day = CarbonImmutable::parse($date);
        $window = HomeIssueDay::window($day);

        $issue = HomeIssue::factory()->create([
            'issue_date' => $day->toDateString(),
            'window_start' => $window->start,
            'published_at' => $window->end,
        ]);

        HomeIssueItem::factory()->forSource(Diary::factory()->create())->create([
            'home_issue_id' => $issue->getKey(),
            'section' => HomeIssueSection::Stories,
            'rank' => 1,
        ]);

        return $issue;
    }
}

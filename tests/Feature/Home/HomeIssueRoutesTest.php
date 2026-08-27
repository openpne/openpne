<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

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
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The two issue URLs.
 *
 * Modern-only, like `/groups/recent`: there is no OpenPNE 3 screen to be compatible with, so both
 * render Inertia whatever surface the site or the member is on, and the look swaps the page rather
 * than the route.
 */
class HomeIssueRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-08-27 06:00:00';

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
     * A well-formed URL naming a day that never happened reads as nothing, not as an error — and
     * never as the day it would roll over into: February 30th is March 2nd to a date constructor,
     * and the issue published on the 2nd answers to its own URL only.
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

    /**
     * The pager reads the run by day: the issue either side, and nothing past either end.
     *
     * Through the route rather than against the query, because the neighbours are what the page is
     * handed — a pager pointing at the wrong day is the same bug whichever half produced it.
     */
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

    /** One issue dated on the frozen clock, with one story in it. */
    private function publish(): HomeIssue
    {
        return $this->publishOn(self::NOW);
    }

    /** One issue covering $date, with one story in it. */
    private function publishOn(string $date): HomeIssue
    {
        $day = CarbonImmutable::parse($date);

        $issue = HomeIssue::factory()->create([
            'issue_date' => $day->toDateString(),
            'window_start' => $day->subDay(),
            'published_at' => $day->setTime(6, 0),
        ]);

        HomeIssueItem::factory()->forSource(Diary::factory()->create())->create([
            'home_issue_id' => $issue->getKey(),
            'section' => HomeIssueSection::Stories,
            'rank' => 1,
        ]);

        return $issue;
    }
}

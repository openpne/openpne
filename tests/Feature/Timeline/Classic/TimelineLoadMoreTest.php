<?php

namespace Tests\Feature\Timeline\Classic;

use App\Models\Gadget;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Services\GadgetService;
use App\Support\Stream\StreamCursor;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TimelineLoadMoreTest extends TestCase
{
    use RefreshDatabase;

    private const FETCH = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html'];

    private function posts(Member $author, int $count, string $prefix = 'Row', ?string $at = null): void
    {
        for ($i = 1; $i <= $count; $i++) {
            TimelinePost::factory()->create([
                'member_id' => $author->getKey(),
                'visibility' => Visibility::Members,
                'body' => sprintf('%s %02d', $prefix, $i),
                'created_at' => $at ?? now()->subMinutes($count - $i),
            ]);
        }
    }

    /** The cursor a page hands out for the row with this body, as the server would print it. */
    private function cursorOf(string $body): string
    {
        return (string) StreamCursor::of(TimelinePost::query()->where('body', $body)->sole());
    }

    /** The cursor for the n-th newest post, the row a page of n rows ends on. */
    private function cursorAt(int $n): string
    {
        return (string) StreamCursor::of(TimelinePost::query()->orderByDesc('created_at')->orderByDesc('id')->skip($n - 1)->firstOrFail());
    }

    /** The URL a rows response names as its next page, or null. */
    private function nextOf(TestResponse $response): ?string
    {
        $link = $response->headers->get('Link');

        return $link === null ? null : substr($link, 1, strpos($link, '>') - 1);
    }

    public function test_a_rows_page_is_bare_uncached_and_names_the_page_after_it_by_cursor(): void
    {
        $author = Member::factory()->create();
        $this->posts($author, 41);

        $response = $this->actingAs($author)->withHeaders(self::FETCH)->get('/timeline/rows')->assertOk();

        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('Link', '<'.route('timeline.index.rows', ['per_page' => 20, 'before' => $this->cursorOf('Row 22')]).'>; rel="next"');
        $this->assertSame(20, substr_count($response->getContent(), '<div class="timeline-post"'));
        $response->assertSee('Row 41')->assertSee('Row 22')->assertDontSee('Row 21');
        $response->assertDontSee('<script', false)->assertDontSee('data-timeline-loadmore-box', false)->assertDontSee('pagerRelative', false);
    }

    public function test_the_url_a_page_hands_out_reads_back_as_the_same_position(): void
    {
        $author = Member::factory()->create();
        $this->posts($author, 41);

        $second = $this->actingAs($author)->withHeaders(self::FETCH)->get($this->nextOf($this->actingAs($author)->withHeaders(self::FETCH)->get('/timeline/rows')))->assertOk();
        $second->assertSee('Row 21')->assertSee('Row 02')->assertDontSee('Row 22')->assertDontSee('Row 01');
        $this->assertSame(20, substr_count($second->getContent(), '<div class="timeline-post"'));

        $last = $this->actingAs($author)->withHeaders(self::FETCH)->get($this->nextOf($second))->assertOk();
        $last->assertSee('Row 01')->assertDontSee('Row 02')->assertHeaderMissing('Link');
    }

    public function test_a_post_made_meanwhile_does_not_shift_the_next_page(): void
    {
        $author = Member::factory()->create();
        $this->posts($author, 41);
        $next = $this->nextOf($this->actingAs($author)->withHeaders(self::FETCH)->get('/timeline/rows'));

        $this->posts($author, 1, 'Meanwhile');

        $this->actingAs($author)->withHeaders(self::FETCH)->get($next)->assertOk()
            ->assertSee('Row 21')->assertSee('Row 02')->assertDontSee('Row 22')->assertDontSee('Row 01')->assertDontSee('Meanwhile');
    }

    public function test_a_boundary_inside_one_second_loses_and_repeats_nothing(): void
    {
        $author = Member::factory()->create();
        $this->posts($author, 25, 'Tie', now()->format('Y-m-d H:i:s'));

        $head = $this->actingAs($author)->withHeaders(self::FETCH)->get('/timeline/rows')->assertOk();
        $rest = $this->actingAs($author)->withHeaders(self::FETCH)->get($this->nextOf($head))->assertOk();

        preg_match_all('/data-timeline-id="(\d+)"/', $head->getContent(), $a);
        preg_match_all('/data-timeline-id="(\d+)"/', $rest->getContent(), $b);
        $ids = [...array_unique($a[1]), ...array_unique($b[1])];
        $this->assertCount(25, $ids);
        $this->assertSame(TimelinePost::query()->orderByDesc('id')->pluck('id')->map(fn (int $id) => (string) $id)->all(), $ids);
    }

    public function test_a_page_size_survives_to_the_page_after(): void
    {
        // A gadget with limit=5 fetches its second page at five; its third must stay at five.
        $author = Member::factory()->create();
        $this->posts($author, 12);

        $response = $this->actingAs($author)->withHeaders(self::FETCH)->get('/timeline/rows?per_page=5')->assertOk();

        $this->assertSame(5, substr_count($response->getContent(), '<div class="timeline-post"'));
        $response->assertHeader('Link', '<'.route('timeline.index.rows', ['per_page' => 5, 'before' => $this->cursorOf('Row 08')]).'>; rel="next"');
    }

    public function test_a_page_size_is_capped_and_a_stale_page_url_is_refused(): void
    {
        $author = Member::factory()->create();

        $this->actingAs($author)->getJson('/timeline/rows?per_page=51')->assertStatus(422);
        // A tab from before the list became a stream still holds a ?page= load-more URL; the head served twice would double the rows.
        $this->actingAs($author)->getJson('/timeline/rows?page=2')->assertStatus(400);
        $this->actingAs($author)->getJson('/timeline/rows?page=1')->assertStatus(400);
    }

    public function test_a_malformed_cursor_reads_as_the_head(): void
    {
        $author = Member::factory()->create();
        $this->posts($author, 3);

        $this->actingAs($author)->withHeaders(self::FETCH)->get('/timeline/rows?before=nonsense')->assertOk()->assertSee('Row 03');
        $this->actingAs($author)->get('/timeline?before[]=x')->assertOk()->assertSee('Row 03');
    }

    public function test_the_rows_are_gated_like_the_screens(): void
    {
        $author = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey()]);

        $this->get('/timeline/rows')->assertRedirect('/login');
        $this->get("/member/{$author->getKey()}/timeline/rows")->assertRedirect('/login');
        $this->actingAs($author)->withHeaders(self::FETCH)->get('/member/999999/timeline/rows')->assertNotFound();
        // A member the owner blocks gets the same 404 as the screen: the rows are no oracle.
        $blocked = Member::factory()->create();
        DB::table('member_blocks')->insert(['blocker_id' => $author->getKey(), 'blocked_id' => $blocked->getKey()]);
        $this->actingAs($blocked)->withHeaders(self::FETCH)->get("/member/{$author->getKey()}/timeline")->assertNotFound();
        $this->actingAs($blocked)->withHeaders(self::FETCH)->get("/member/{$author->getKey()}/timeline/rows")->assertNotFound();
        $this->actingAs($author)->withHeaders(self::FETCH)->get("/member/{$author->getKey()}/timeline/rows")->assertOk()
            ->assertSee($post->body);
    }

    public function test_the_member_and_tag_rows_name_their_own_next_page(): void
    {
        $author = Member::factory()->create();
        for ($i = 1; $i <= 21; $i++) {
            $post = TimelinePost::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members, 'body' => "Tagged #tag {$i}", 'created_at' => now()->subMinutes(21 - $i)]);
            $post->tags()->create(['tag' => 'tag', 'offset' => 7, 'length' => 4]);
        }
        $cursor = $this->cursorOf('Tagged #tag 2');

        $this->actingAs($author)->withHeaders(self::FETCH)->get("/member/{$author->getKey()}/timeline/rows")->assertOk()
            ->assertHeader('Link', '<'.route('timeline.member.rows', ['member' => $author, 'per_page' => 20, 'before' => $cursor]).'>; rel="next"');
        $this->actingAs($author)->withHeaders(self::FETCH)->get('/timeline/tag/tag/rows')->assertOk()
            ->assertHeader('Link', '<'.route('timeline.tag.rows', ['tag' => 'tag', 'per_page' => 20, 'before' => $cursor]).'>; rel="next"');
    }

    public function test_a_screen_offers_the_control_from_its_own_position_and_keeps_the_pager(): void
    {
        $author = Member::factory()->create();
        $this->posts($author, 41);
        $cursor = $this->cursorOf('Row 22');

        // Hidden until the script runs (no-JS keeps the pager); the head has no "previous".
        $head = $this->actingAs($author)->get('/timeline')->assertOk()
            ->assertSeeInOrder([
                '<div class="timeline" data-timeline-container>',
                '<div id="timeline-list">',
                '<div data-timeline-loadmore-box hidden>',
                'data-next-url="'.e(route('timeline.index.rows', ['before' => $cursor])).'"',
                '<div data-timeline-pager><div class="pagerRelative">',
                '<p class="next"><a href="'.e(route('timeline.index', ['before' => $cursor])).'">',
            ], false)
            ->assertDontSee('<p class="prev">', false);

        // The older page continues from where the head stopped and offers the head as "previous".
        $older = $this->actingAs($author)->get(route('timeline.index', ['before' => $cursor]))->assertOk()
            ->assertSee('Row 21')->assertDontSee('Row 22')->assertDontSee('Row 01')
            ->assertSee('<p class="prev"><a href="'.e(route('timeline.index')).'">', false)
            ->assertSee('data-next-url="'.e(route('timeline.index.rows', ['before' => $this->cursorOf('Row 02')])).'"', false);

        // The last page: nothing older, so no control and no "next", but the way back stays.
        $this->actingAs($author)->get(route('timeline.index', ['before' => $this->cursorOf('Row 02')]))->assertOk()
            ->assertSee('Row 01')
            ->assertDontSee('data-timeline-loadmore-box', false)
            ->assertDontSee('<p class="next">', false)
            ->assertSee('<div data-timeline-pager><div class="pagerRelative">', false);

        $this->actingAs($author)->get("/member/{$author->getKey()}/timeline")->assertOk()
            ->assertSee('data-next-url="'.e(route('timeline.member.rows', ['member' => $author, 'before' => $cursor])).'"', false);
        $this->assertNotNull($head);
        $this->assertNotNull($older);
    }

    public function test_a_page_from_the_offset_days_is_sent_to_the_head(): void
    {
        $author = Member::factory()->create();

        $this->actingAs($author)->get('/timeline?page=2')->assertRedirect('/timeline');
        $this->actingAs($author)->get("/member/{$author->getKey()}/timeline?page=3")->assertRedirect("/member/{$author->getKey()}/timeline");
        $this->actingAs($author)->get('/timeline/tag/tag?page=2')->assertRedirect('/timeline/tag/tag');
        $this->actingAs($author)->get('/timeline?page=1')->assertOk();

        // A blocked viewer sees the uniform 404 before the redirect could confirm the member exists.
        $blocked = Member::factory()->create();
        DB::table('member_blocks')->insert(['blocker_id' => $author->getKey(), 'blocked_id' => $blocked->getKey()]);
        $this->actingAs($blocked)->get("/member/{$author->getKey()}/timeline?page=3")->assertNotFound();
        $this->actingAs($blocked)->getJson("/member/{$author->getKey()}/timeline/rows?page=2")->assertNotFound();
        $this->actingAs($blocked)->getJson("/member/{$author->getKey()}/timeline/rows?per_page=999")->assertNotFound();
    }

    public function test_a_gadget_offers_more_only_past_its_limit(): void
    {
        $viewer = Member::factory()->create();
        Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'timelineAll', 'sort_order' => 0]);
        Gadget::create(['context' => 'profile', 'zone' => 'contents', 'name' => 'timelineProfile', 'sort_order' => 0]);
        app(GadgetService::class)->clearCache();
        $all = Gadget::where('name', 'timelineAll')->sole();
        $all->configs()->create(['name' => 'limit', 'value' => '2']);
        app(GadgetService::class)->clearCache();

        $this->posts($viewer, 2);
        $this->actingAs($viewer)->get('/')->assertOk()->assertDontSee('data-timeline-loadmore-box', false);

        // One past the limit: the button, fetching the page after the two shown at the gadget's own size.
        $this->posts($viewer, 1, 'Third');
        $this->actingAs($viewer)->get('/')->assertOk()
            ->assertSee('data-next-url="'.e(route('timeline.index.rows', ['before' => $this->cursorAt(2), 'per_page' => 2])).'"', false)
            ->assertDontSee('Row 01');

        // The profile gadget has no config: twenty rows, the member rows' own default.
        $this->posts($viewer, 17, 'More');
        $this->actingAs($viewer)->get("/member/{$viewer->getKey()}")->assertOk()->assertDontSee('data-timeline-loadmore-box', false);
        $this->posts($viewer, 1, 'Twentyfirst');
        $this->actingAs($viewer)->get("/member/{$viewer->getKey()}")->assertOk()
            ->assertSee('data-next-url="'.e(route('timeline.member.rows', ['member' => $viewer, 'before' => $this->cursorAt(20)])).'"', false);
    }

    public function test_two_gadgets_share_one_script_and_stylesheet(): void
    {
        $viewer = Member::factory()->create();
        Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'timelineAll', 'sort_order' => 0]);
        Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'timelineFriend', 'sort_order' => 1]);
        app(GadgetService::class)->clearCache();

        $html = $this->actingAs($viewer)->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'js/classic-timeline-more.js'));
        $this->assertSame(1, substr_count($html, 'css/classic-timeline.css'));
    }

    public function test_a_gadget_limit_is_held_to_the_page_the_rows_will_serve(): void
    {
        // limit=60 would have the button ask for a page the fragment refuses (per_page ≤ 50).
        $viewer = Member::factory()->create();
        $gadget = Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'timelineAll', 'sort_order' => 0]);
        $gadget->configs()->create(['name' => 'limit', 'value' => '60']);
        app(GadgetService::class)->clearCache();
        $this->posts($viewer, 52);

        $html = $this->actingAs($viewer)->get('/')->assertOk()->getContent();

        $this->assertSame(50, substr_count($html, '<div class="timeline-post"'));
        $this->assertStringContainsString('data-next-url="'.e(route('timeline.index.rows', ['before' => $this->cursorOf('Row 03'), 'per_page' => 50])).'"', $html);
    }

    public function test_the_friend_gadget_offers_no_control(): void
    {
        $viewer = Member::factory()->create();
        Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'timelineFriend', 'sort_order' => 0]);
        app(GadgetService::class)->clearCache();
        $this->posts($viewer, 21);

        $this->actingAs($viewer)->get('/')->assertOk()
            ->assertSee('data-timeline-container', false)
            ->assertDontSee('data-timeline-loadmore-box', false);
    }

    public function test_a_cursor_page_with_nothing_older_says_so_rather_than_calling_the_feed_empty(): void
    {
        $author = Member::factory()->create();
        $this->posts($author, 1);

        $this->actingAs($author)->get(route('timeline.index', ['before' => $this->cursorOf('Row 01')]))->assertOk()
            ->assertSee(__('No older posts.'))
            ->assertDontSee(__('No %activity% posts to show.'))
            ->assertSee('<p class="prev"><a href="'.e(route('timeline.index')).'">', false);
        $this->actingAs($author)->get(route('timeline.member', ['member' => $author, 'before' => $this->cursorOf('Row 01')]))->assertOk()
            ->assertSee(__('No older posts.'))
            ->assertDontSee(__('No %activity% posts to show.'))
            ->assertSee('<p class="prev"><a href="'.e(route('timeline.member', ['member' => $author])).'">', false);
        // The tag arrives unnormalized; the head link names the normalized form the page is on.
        $this->actingAs($author)->get(route('timeline.tag', ['tag' => 'ＲＯＷ', 'before' => $this->cursorOf('Row 01')]))->assertOk()
            ->assertSee(__('No older posts.'))
            ->assertDontSee(__('No %activity% posts to show.'))
            ->assertSee('<p class="prev"><a href="'.e(route('timeline.tag', ['tag' => 'row'])).'">', false);

        // The head with no rows at all is the empty feed, with no pager to draw.
        $other = Member::factory()->create();
        $this->actingAs($other)->get(route('timeline.member', ['member' => $other]))->assertOk()
            ->assertSee(__('No %activity% posts to show.'))
            ->assertDontSee(__('No older posts.'))
            ->assertDontSee('<p class="prev">', false);
    }
}

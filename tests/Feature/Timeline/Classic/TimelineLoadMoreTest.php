<?php

namespace Tests\Feature\Timeline\Classic;

use App\Models\Gadget;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Services\GadgetService;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Classic もっと読む: a rows fragment per page with the page after it in a Link header, offered
 * by the screens from their own page and by the gadgets only past their limit; the pager stands in
 * without the script.
 */
class TimelineLoadMoreTest extends TestCase
{
    use RefreshDatabase;

    private const FETCH = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html'];

    private function posts(Member $author, int $count, string $prefix = 'Row'): void
    {
        for ($i = 1; $i <= $count; $i++) {
            TimelinePost::factory()->create([
                'member_id' => $author->getKey(),
                'visibility' => Visibility::Members,
                'body' => sprintf('%s %02d', $prefix, $i),
                'created_at' => now()->subMinutes($count - $i),
            ]);
        }
    }

    public function test_a_rows_page_is_bare_uncached_and_names_the_page_after_it(): void
    {
        $author = Member::factory()->create();
        $this->posts($author, 41);

        $response = $this->actingAs($author)->withHeaders(self::FETCH)->get('/timeline/rows?page=2')->assertOk();

        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('Link', '<'.route('timeline.index.rows', ['per_page' => 20, 'page' => 3]).'>; rel="next"');
        $this->assertSame(20, substr_count($response->getContent(), '<div class="timeline-post"'));
        $response->assertSee('Row 21')->assertDontSee('Row 41')->assertDontSee('Row 01');
        $response->assertDontSee('<script', false)->assertDontSee('data-timeline-loadmore-box', false)->assertDontSee('pagerRelative', false);
    }

    public function test_the_last_page_names_no_next(): void
    {
        $author = Member::factory()->create();
        $this->posts($author, 41);

        $this->actingAs($author)->withHeaders(self::FETCH)->get('/timeline/rows?page=3')->assertOk()
            ->assertHeaderMissing('Link')
            ->assertSee('Row 01');
    }

    public function test_a_page_size_survives_to_the_page_after(): void
    {
        // A gadget with limit=5 fetches its second page at five; its third must stay at five.
        $author = Member::factory()->create();
        $this->posts($author, 12);

        $response = $this->actingAs($author)->withHeaders(self::FETCH)->get('/timeline/rows?page=2&per_page=5')->assertOk();

        $this->assertSame(5, substr_count($response->getContent(), '<div class="timeline-post"'));
        $response->assertHeader('Link', '<'.route('timeline.index.rows', ['per_page' => 5, 'page' => 3]).'>; rel="next"');
    }

    public function test_a_page_size_is_capped(): void
    {
        $author = Member::factory()->create();

        $this->actingAs($author)->getJson('/timeline/rows?per_page=51')->assertStatus(422);
        $this->actingAs($author)->getJson('/timeline/rows?page=0')->assertStatus(422);
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
            $post = TimelinePost::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members, 'body' => "Tagged #tag {$i}"]);
            $post->tags()->create(['tag' => 'tag', 'offset' => 7, 'length' => 4]);
        }

        $this->actingAs($author)->withHeaders(self::FETCH)->get("/member/{$author->getKey()}/timeline/rows")->assertOk()
            ->assertHeader('Link', '<'.route('timeline.member.rows', ['member' => $author, 'per_page' => 20, 'page' => 2]).'>; rel="next"');
        $this->actingAs($author)->withHeaders(self::FETCH)->get('/timeline/tag/tag/rows')->assertOk()
            ->assertHeader('Link', '<'.route('timeline.tag.rows', ['tag' => 'tag', 'per_page' => 20, 'page' => 2]).'>; rel="next"');
    }

    public function test_a_screen_offers_the_control_from_its_own_page_and_keeps_the_pager(): void
    {
        $author = Member::factory()->create();
        $this->posts($author, 41);

        // Hidden until the script runs (no-JS keeps the pager), and the next page counts from the
        // page the reader is on: /timeline?page=2 continues at 3, not 2.
        $this->actingAs($author)->get('/timeline?page=2')->assertOk()
            ->assertSeeInOrder([
                '<div class="timeline" data-timeline-container>',
                '<div id="timeline-list">',
                '<div data-timeline-loadmore-box hidden>',
                'data-next-url="'.e(route('timeline.index.rows', ['page' => 3])).'"',
                '<div data-timeline-pager><div class="pagerRelative">',
            ], false);
        $this->actingAs($author)->get('/timeline?page=3')->assertOk()
            ->assertDontSee('data-timeline-loadmore-box', false)
            ->assertSee('<div data-timeline-pager>', false);
        $this->actingAs($author)->get("/member/{$author->getKey()}/timeline")->assertOk()
            ->assertSee('data-next-url="'.e(route('timeline.member.rows', ['member' => $author, 'page' => 2])).'"', false);
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

        // One past the limit: the button, fetching the second page at the gadget's own size.
        $this->posts($viewer, 1, 'Third');
        $this->actingAs($viewer)->get('/')->assertOk()
            ->assertSee('data-next-url="'.e(route('timeline.index.rows', ['page' => 2, 'per_page' => 2])).'"', false)
            ->assertDontSee('Row 01');

        // The profile gadget has no config: twenty rows, the member rows' own default.
        $this->posts($viewer, 17, 'More');
        $this->actingAs($viewer)->get("/member/{$viewer->getKey()}")->assertOk()->assertDontSee('data-timeline-loadmore-box', false);
        $this->posts($viewer, 1, 'Twentyfirst');
        $this->actingAs($viewer)->get("/member/{$viewer->getKey()}")->assertOk()
            ->assertSee('data-next-url="'.e(route('timeline.member.rows', ['member' => $viewer, 'page' => 2])).'"', false);
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
        $this->assertStringContainsString('data-next-url="'.e(route('timeline.index.rows', ['page' => 2, 'per_page' => 50])).'"', $html);
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
}

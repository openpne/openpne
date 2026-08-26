<?php

namespace Tests\Feature\Timeline\Classic;

use App\Features\Timeline\Queries\RecentReplies;
use App\Models\File;
use App\Models\Gadget;
use App\Models\LinkCard;
use App\Models\Member;
use App\Models\MemberImage;
use App\Models\TimelinePost;
use App\Services\GadgetService;
use App\Support\LinkCardStatus;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The inline reply layer a Classic feed row carries: the tail of the thread, in reading order, for
 * one page's worth of queries however many rows there are.
 */
class TimelineRecentRepliesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::LinkCardEnabled, true);
    }

    public function test_a_row_carries_the_last_ten_replies_oldest_first(): void
    {
        $author = Member::factory()->create();
        $post = $this->makePost($author);
        for ($i = 1; $i <= 12; $i++) {
            $this->reply($post, $author, "Reply number {$i}.");
        }

        $html = $this->actingAs($author)->get(route('timeline.index'))->assertOk()->getContent();

        // Ten, not twelve: SQLite below 3.25 compiles the partition away and returns the lot, which
        // reads as a working page right up until a thread gets long.
        $this->assertSame(10, substr_count($html, '<div class="timeline-post-comment" data-timeline-id='));
        $this->assertStringNotContainsString('Reply number 1.', $html);
        $this->assertStringNotContainsString('Reply number 2.', $html);
        $this->assertLessThan(strpos($html, 'Reply number 12.'), strpos($html, 'Reply number 3.'));
    }

    public function test_a_thread_shorter_than_the_cap_shows_all_of_it(): void
    {
        $author = Member::factory()->create();
        $post = $this->makePost($author);
        $this->reply($post, $author, 'The only answer.');

        $this->actingAs($author)->get(route('timeline.index'))->assertOk()
            ->assertSee('The only answer.')
            ->assertSee('id="commentlist-'.$post->getKey().'"', false);
    }

    public function test_a_feed_costs_the_same_however_many_rows_carry_replies(): void
    {
        $author = Member::factory()->create();
        $this->postWithFullReply($author);
        $this->actingAs($author)->get(route('timeline.index')); // warm the per-request caches

        DB::enableQueryLog();
        $this->actingAs($author)->get(route('timeline.index'))->assertOk();
        $oneRow = count(DB::getQueryLog());

        for ($i = 0; $i < 19; $i++) {
            $this->postWithFullReply($author);
        }
        DB::flushQueryLog();
        $html = $this->actingAs($author)->get(route('timeline.index'))->assertOk()->getContent();
        $twentyRows = count(DB::getQueryLog());
        DB::disableQueryLog();

        // A page of the maximum size, so a per-row read would be 20x rather than 2x.
        $this->assertSame(20, substr_count($html, '<div class="timeline-post-comment" data-timeline-id='));
        $this->assertSame(20, substr_count($html, 'A title from the page'));
        $this->assertSame($oneRow, $twentyRows);
    }

    public function test_the_home_gadget_costs_the_same_however_many_rows_carry_replies(): void
    {
        // The gadget components attach the layer themselves; a missing attach lazy-loads per row.
        Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => 'timelineAll', 'sort_order' => 0]);
        app(GadgetService::class)->clearCache();
        $author = Member::factory()->create();
        $this->postWithFullReply($author);
        $this->actingAs($author)->get('/');

        DB::enableQueryLog();
        $this->actingAs($author)->get('/')->assertOk();
        $oneRow = count(DB::getQueryLog());

        for ($i = 0; $i < 19; $i++) {
            $this->postWithFullReply($author);
        }
        DB::flushQueryLog();
        $html = $this->actingAs($author)->get('/')->assertOk()->getContent();
        $twentyRows = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(20, substr_count($html, '<div class="timeline-post-comment" data-timeline-id='));
        $this->assertSame($oneRow, $twentyRows);
    }

    public function test_only_the_classic_surface_pays_for_the_layer(): void
    {
        // The relation is attached by the Classic responder, not by the feed query — Modern's
        // serializer carries a reply count and would load ten replies per row for nothing.
        config(['openpne.surface_mode' => 'modern_default']);
        $author = Member::factory()->create();
        $post = $this->makePost($author);
        $this->reply($post, $author, 'Invisible to Modern.');

        DB::enableQueryLog();
        $this->actingAs($author)->get(route('timeline.index'))->assertOk()
            ->assertDontSee('Invisible to Modern.');
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        // The tail is the one window-function query on the page; Modern must not run it.
        $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => stripos($sql, 'row_number') !== false)));
    }

    private function makePost(Member $author): TimelinePost
    {
        return TimelinePost::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);
    }

    private function reply(TimelinePost $post, Member $author, string $body): TimelinePost
    {
        return TimelinePost::factory()->replyTo($post)->create(['member_id' => $author->getKey(), 'body' => $body]);
    }

    /**
     * A post whose one reply reads every relation the layer eager-loads: the replier's avatar file,
     * a mention range, a hashtag range, and a link card with a picture. Without all four a missing
     * eager load costs nothing and the query-count pin passes on a page that would N+1 in production.
     */
    private function postWithFullReply(Member $author): TimelinePost
    {
        $replier = Member::factory()->create(['name' => 'Alice']);
        MemberImage::factory()->create([
            'member_id' => $replier->getKey(),
            'file_id' => File::factory()->create(['type' => 'image/png', 'width' => 200, 'height' => 200])->getKey(),
        ]);

        $post = $this->makePost($author);
        $reply = $this->reply($post, $replier, 'hi @Alice #tag https://example.com/');
        $reply->mentions()->create(['member_id' => $replier->getKey(), 'offset' => 3, 'length' => 6]);
        $reply->tags()->create(['tag' => 'tag', 'offset' => 10, 'length' => 4]);

        $card = LinkCard::factory()->create(['status' => LinkCardStatus::Ok, 'title' => 'A title from the page']);
        $card->update(['image_file_id' => File::factory()->create(['type' => 'image/png', 'width' => 1200, 'height' => 630])->getKey()]);
        // Assigned rather than filled (link_card_id is not fillable), and marked synced so the read
        // trigger does not queue a fetch of its own while the count is running.
        $reply->link_card_id = $card->getKey();
        $reply->link_card_synced_at = now();
        $reply->save();

        return $post;
    }

    public function test_the_cap_is_the_one_the_row_offers_to_look_past(): void
    {
        // The load-more control renders on replies_count > LIMIT, so the two must be the same number.
        $this->assertSame(10, RecentReplies::LIMIT);
    }
}

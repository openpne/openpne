<?php

namespace Tests\Feature\Timeline\Classic;

use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A stored mention range draws an anchor on every Classic screen the timeline row reaches, and a
 * range whose row is gone reads as the plain text it always was.
 */
class MentionRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_permalink_links_the_mention_to_the_member(): void
    {
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $post = $this->postMentioning($author, $alice);

        $this->actingAs($author)->get("/timeline/{$post->getKey()}")
            ->assertOk()
            ->assertSee($this->anchor($alice), false);
    }

    public function test_a_reply_links_its_own_mention(): void
    {
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);
        $this->postMentioning($author, $alice, $post);

        $this->actingAs($author)->get("/timeline/{$post->getKey()}")
            ->assertOk()
            ->assertSee($this->anchor($alice), false);
    }

    public function test_the_member_timeline_and_the_home_feed_link_the_mention(): void
    {
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $this->postMentioning($author, $alice);

        $this->actingAs($author)->get("/member/{$author->getKey()}/timeline")
            ->assertOk()
            ->assertSee($this->anchor($alice), false);
        $this->actingAs($author)->get('/timeline')
            ->assertOk()
            ->assertSee($this->anchor($alice), false);
    }

    public function test_a_mentioned_name_carrying_markup_is_escaped(): void
    {
        $author = Member::factory()->create();
        $bob = Member::factory()->create(['name' => '<b>Bob</b>']);
        $post = $this->postMentioning($author, $bob);

        $this->actingAs($author)->get("/timeline/{$post->getKey()}")
            ->assertOk()
            ->assertSee('class="mention">@&lt;b&gt;Bob&lt;/b&gt;</a>', false)
            ->assertDontSee('<b>Bob</b>', false);
    }

    public function test_a_range_whose_member_is_gone_renders_as_plain_text(): void
    {
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $post = $this->postMentioning($author, $alice);

        // The mention row cascades with the member; the body keeps the handle it always carried.
        $alice->delete();

        $this->actingAs($author)->get("/timeline/{$post->getKey()}")
            ->assertOk()
            ->assertSee('hi @Alice')
            ->assertDontSee('class="mention"', false);
    }

    public function test_a_feed_costs_the_same_however_many_rows_carry_mentions(): void
    {
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $this->postMentioning($author, $alice);
        $this->actingAs($author)->get('/timeline'); // warm the per-request caches the count would include

        DB::enableQueryLog();
        $this->actingAs($author)->get('/timeline')->assertOk();
        $oneRow = count(DB::getQueryLog());

        for ($i = 0; $i < 4; $i++) {
            $this->postMentioning($author, $alice);
        }
        DB::flushQueryLog();
        $this->actingAs($author)->get('/timeline')->assertOk()->assertSee($this->anchor($alice), false);
        $fiveRows = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($oneRow, $fiveRows);
    }

    /** The anchor <x-timeline-body> draws for a mention of $member. */
    private function anchor(Member $member): string
    {
        return '<a href="'.route('member.profile.show', $member).'" class="mention">@'.e($member->name).'</a>';
    }

    private function postMentioning(Member $author, Member $mentioned, ?TimelinePost $parent = null): TimelinePost
    {
        $handle = '@'.$mentioned->name;
        $post = TimelinePost::factory()->create([
            'member_id' => $author->getKey(),
            'in_reply_to_id' => $parent?->getKey(),
            'visibility' => Visibility::Members,
            'body' => 'hi '.$handle,
        ]);
        $post->mentions()->create([
            'member_id' => $mentioned->getKey(),
            'offset' => 3,
            'length' => mb_strlen($handle),
        ]);

        return $post;
    }
}

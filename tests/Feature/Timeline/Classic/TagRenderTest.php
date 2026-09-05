<?php

namespace Tests\Feature\Timeline\Classic;

use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Data\TimelinePostFormData;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagRenderTest extends TestCase
{
    use RefreshDatabase;

    // The anchor -----------------------------------------------------------------

    public function test_a_hashtag_links_to_its_tag_page_on_the_feed_and_the_permalink(): void
    {
        $author = Member::factory()->create();
        $post = $this->createPost($author, 'shipped #op4 today');

        $anchor = '<a href="'.route('timeline.tag', 'op4').'" class="hashtag">#op4</a>';

        $this->actingAs($author)->get('/timeline')->assertOk()->assertSee($anchor, false);
        $this->actingAs($author)->get("/timeline/{$post->getKey()}")->assertOk()->assertSee($anchor, false);
        $this->actingAs($author)->get("/member/{$author->getKey()}/timeline")->assertOk()->assertSee($anchor, false);
    }

    public function test_a_mention_and_a_hashtag_in_one_body_are_both_linked(): void
    {
        $author = Member::factory()->create();
        $alice = Member::factory()->create(['name' => 'Alice']);
        $post = TimelinePost::factory()->create([
            'member_id' => $author->getKey(),
            'visibility' => Visibility::Members,
            'body' => 'hi @Alice #op4',
        ]);
        $post->mentions()->create(['member_id' => $alice->getKey(), 'offset' => 3, 'length' => 6]);
        $post->tags()->create(['tag' => 'op4', 'offset' => 10, 'length' => 4]);

        $this->actingAs($author)->get("/timeline/{$post->getKey()}")
            ->assertOk()
            ->assertSee('hi <a href="'.route('member.profile.show', $alice).'" class="mention">@Alice</a> '
                .'<a href="'.route('timeline.tag', 'op4').'" class="hashtag">#op4</a>', false);
    }

    public function test_the_marker_is_shown_as_typed_while_the_href_carries_the_normalized_tag(): void
    {
        $author = Member::factory()->create();
        $this->createPost($author, '＃ＴＡＧ です');

        $this->actingAs($author)->get('/timeline')
            ->assertOk()
            ->assertSee('<a href="'.route('timeline.tag', 'tag').'" class="hashtag">＃ＴＡＧ</a>', false);
    }

    public function test_a_japanese_tag_href_is_percent_encoded(): void
    {
        // The link the body draws and the URL the page answers on are the same string, so the
        // encoding is pinned against route()'s own output rather than a hand-written path.
        $author = Member::factory()->create();
        $this->createPost($author, 'きょうは #タグ の日');

        $this->assertSame(url('/timeline/tag/%E3%82%BF%E3%82%B0'), route('timeline.tag', 'タグ'));
        $this->actingAs($author)->get('/timeline')
            ->assertOk()
            ->assertSee('<a href="'.route('timeline.tag', 'タグ').'" class="hashtag">#タグ</a>', false);
    }

    // The page -------------------------------------------------------------------

    public function test_the_tag_page_lists_the_posts_carrying_the_tag(): void
    {
        $author = Member::factory()->create();
        $tagged = $this->createPost($author, 'shipped #op4 today');
        $untagged = $this->createPost($author, 'nothing to see here');

        $this->actingAs($author)->get(route('timeline.tag', 'op4'))
            ->assertOk()
            ->assertSee('shipped')
            ->assertDontSee('nothing to see here')
            ->assertSee("data-timeline-id=\"{$tagged->getKey()}\"", false)
            ->assertDontSee("data-timeline-id=\"{$untagged->getKey()}\"", false);
    }

    public function test_the_tag_page_names_the_normalized_tag_and_is_reached_by_an_upper_case_url(): void
    {
        $author = Member::factory()->create();
        $this->createPost($author, 'shipped #op4 today');

        $this->actingAs($author)->get('/timeline/tag/OP4')
            ->assertOk()
            ->assertSee('<h3>'.__('%Activity% posts tagged #:tag', ['tag' => 'op4']).'</h3>', false)
            ->assertSee('shipped');
    }

    public function test_a_japanese_tag_page_is_reached_through_its_encoded_url(): void
    {
        $author = Member::factory()->create();
        $this->createPost($author, 'きょうは #タグ の日');

        $this->actingAs($author)->get(route('timeline.tag', 'タグ'))
            ->assertOk()
            ->assertSee('きょうは');
    }

    public function test_a_tag_nobody_used_renders_an_empty_page_rather_than_a_404(): void
    {
        // Zero results is an answer, the same one a search with no hits gives; a tag is not an
        // entity that can be missing.
        $this->actingAs(Member::factory()->create())->get(route('timeline.tag', 'nobody'))
            ->assertOk()
            ->assertSee(__('No %activity% posts to show.'));
    }

    public function test_a_post_the_viewer_may_not_see_stays_off_the_tag_page(): void
    {
        [$viewer, $stranger] = Member::factory()->count(2)->create()->all();
        $this->createPost($stranger, 'secret #op4', Visibility::Private);

        $this->actingAs($viewer)->get(route('timeline.tag', 'op4'))
            ->assertOk()
            ->assertDontSee('secret');
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('timeline.tag', 'op4'))->assertRedirect('/login');
    }

    private function createPost(Member $author, string $body, Visibility $visibility = Visibility::Members): TimelinePost
    {
        return app(CreateTimelinePost::class)($author, new TimelinePostFormData($body, $visibility));
    }
}

<?php

namespace Tests\Feature\Timeline\Modern;

use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Stream\StreamCursor;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Inertia;
use Tests\TestCase;

class TimelineHomeFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/timeline')->assertRedirect('/login');
    }

    public function test_modern_home_feed_renders_inertia_component_with_viewer_and_posts(): void
    {
        $member = Member::factory()->create();
        TimelinePost::factory()->create(['member_id' => $member->getKey(), 'visibility' => Visibility::Members]);

        $response = $this->actingAs($member)->get('/timeline');

        $response->assertInertia(fn ($page) => $page
            ->component('timeline/index')
            ->where('viewerId', $member->getKey())
            ->has('posts.data', 1)
            ->missing('posts.meta')
        );
        $this->assertSame(['pageName' => 'before', 'previousPage' => null, 'nextPage' => null, 'currentPage' => null, 'reset' => false], $response->viewData('page')['scrollProps']['posts']);
        // A full render hands out a fresh generation, so a replaced list remounts the client's stream state.
        $generation = $response->viewData('page')['props']['streamGeneration'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $generation);
        $this->assertNotSame($generation, $this->actingAs($member)->get('/timeline')->viewData('page')['props']['streamGeneration']);
        // A "load more" asks for the rows only, so the generation it holds stays and the list is not remounted mid-scroll.
        $partial = $this->actingAs($member)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) Inertia::getVersion(), 'X-Inertia-Partial-Component' => 'timeline/index', 'X-Inertia-Partial-Data' => 'posts'])->get('/timeline')->assertOk()->json('props');
        $this->assertArrayHasKey('posts', $partial);
        $this->assertArrayNotHasKey('streamGeneration', $partial);
    }

    public function test_the_feed_pages_by_cursor_and_the_cursor_travels_in_the_scroll_metadata(): void
    {
        $member = Member::factory()->create();
        TimelinePost::factory()->count(21)->create(['member_id' => $member->getKey(), 'visibility' => Visibility::Members]);

        $head = $this->actingAs($member)->get('/timeline')->viewData('page');
        $cursor = $head['scrollProps']['posts']['nextPage'];
        $next = $this->actingAs($member)->get('/timeline?before='.urlencode($cursor))->viewData('page');

        $this->assertCount(20, $head['props']['posts']['data']);
        $this->assertCount(1, $next['props']['posts']['data']);
        $this->assertSame($cursor, $next['scrollProps']['posts']['currentPage']);
        $this->assertNull($next['scrollProps']['posts']['nextPage']);
        $this->assertSame([], array_intersect(array_column($head['props']['posts']['data'], 'id'), array_column($next['props']['posts']['data'], 'id')));
    }

    public function test_modern_home_feed_carries_the_reply_count_on_top_level_posts(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey(), 'visibility' => Visibility::Members]);
        TimelinePost::factory()->count(2)->create([
            'member_id' => $member->getKey(),
            'in_reply_to_id' => $post->getKey(),
            'visibility' => Visibility::Members,
        ]);

        $this->actingAs($member)
            ->get('/timeline')
            ->assertInertia(fn ($page) => $page
                ->has('posts.data', 1) // replies are not separate rows
                ->where('posts.data.0.id', $post->getKey())
                ->where('posts.data.0.replyCount', 2)
            );
    }

    public function test_home_feed_falls_back_to_classic_with_op3_body_id(): void
    {
        config()->set('features.timeline.modern_status', 'fallback');
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/timeline');

        $response->assertOk();
        $response->assertSee('id="page_timeline_sns"', false);
    }

    public function test_a_page_reached_by_cursor_names_the_head_and_the_head_names_nothing(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey(), 'visibility' => Visibility::Members]);
        $cursor = (string) StreamCursor::of($post);

        $this->actingAs($member)->get('/timeline')->assertInertia(fn ($page) => $page->where('headUrl', null));
        $this->actingAs($member)->get('/timeline?before='.urlencode($cursor))->assertInertia(fn ($page) => $page
            ->has('posts.data', 0)
            ->where('headUrl', route('timeline.index')));
        $this->actingAs($member)->get(route('timeline.member', ['member' => $member, 'before' => $cursor]))->assertInertia(fn ($page) => $page
            ->where('headUrl', route('timeline.member', ['member' => $member])));
        $this->actingAs($member)->get(route('timeline.tag', ['tag' => 'ＴＡＧ', 'before' => $cursor]))->assertInertia(fn ($page) => $page
            ->where('headUrl', route('timeline.tag', ['tag' => 'tag'])));
    }
}

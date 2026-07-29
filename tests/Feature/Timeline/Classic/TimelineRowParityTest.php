<?php

namespace Tests\Feature\Timeline\Classic;

use App\Models\Gadget;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Services\GadgetService;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The OpenPNE 3 timeline row DOM (timelineTemplate) and its component-driven stylesheets,
 * rendered server-side by the Classic adapter.
 */
class TimelineRowParityTest extends TestCase
{
    use RefreshDatabase;

    private function makeHomeGadget(string $name): Gadget
    {
        $gadget = Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => $name, 'sort_order' => 0]);
        app(GadgetService::class)->clearCache();

        return $gadget;
    }

    public function test_the_row_renders_the_op3_dom(): void
    {
        $member = Member::factory()->create(['name' => 'Poster']);
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey(), 'body' => 'Row shape']);

        $response = $this->actingAs($member)->get("/member/{$member->getKey()}/timeline")->assertOk();

        // OpenPNE 3's shell: div.timeline > div#timeline-list holding div rows (not a ul).
        $response->assertSeeInOrder(['<div class="timeline">', '<div id="timeline-list">'], false);
        $response->assertDontSee('<ul class="timeline-list">', false);
        // The row: fragment anchor, 48px avatar block (title on the link), content block, body id.
        $response->assertSeeInOrder([
            '<div class="timeline-post" data-timeline-id="'.$post->getKey().'">',
            '<a name="timeline-'.$post->getKey().'"></a>',
            '<div class="timeline-post-member-image">',
            'title="Poster"',
            'width="48" height="48"',
            '<div class="timeline-post-content">',
            '<a class="screen-name"',
            'id="timeline-post-body-'.$post->getKey().'"',
        ], false);
    }

    public function test_the_visibility_span_follows_op3(): void
    {
        $member = Member::factory()->create();
        $friendsPost = TimelinePost::factory()->create(['member_id' => $member->getKey(), 'visibility' => Visibility::Friends]);

        // The members-wide default renders the control div empty; friend/private/open get the span.
        $this->actingAs($member)->get("/member/{$member->getKey()}/timeline")->assertOk()
            ->assertSee('<span class="public-flag">Visibility:', false);

        $friendsPost->delete();
        TimelinePost::factory()->create(['member_id' => $member->getKey(), 'visibility' => Visibility::Members]);
        $this->actingAs($member)->get("/member/{$member->getKey()}/timeline")->assertOk()
            ->assertDontSee('public-flag', false);

        foreach ([Visibility::Private, Visibility::Open] as $level) {
            TimelinePost::query()->delete();
            TimelinePost::factory()->create(['member_id' => $member->getKey(), 'visibility' => $level]);
            $this->actingAs($member)->get("/member/{$member->getKey()}/timeline")->assertOk()
                ->assertSee('<span class="public-flag">Visibility:', false);
        }
    }

    public function test_the_comment_link_jumps_to_the_thread_reply_form(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)->get("/member/{$member->getKey()}/timeline")->assertOk()
            ->assertSee('href="'.route('timeline.show', $post).'#timeline-reply-form"', false);

        // The thread page anchors its reply form, so the jump lands on it — and its own root row
        // carries the same link as a same-page jump.
        $this->actingAs($member)->get(route('timeline.show', $post))->assertOk()
            ->assertSee('id="timeline-reply-form"', false);
    }

    public function test_the_list_pages_use_the_classic_pager(): void
    {
        $member = Member::factory()->create();
        TimelinePost::factory()->count(21)->create(['member_id' => $member->getKey()]);

        $response = $this->actingAs($member)->get(route('timeline.index'))->assertOk();

        // The Classic pager parts, not Laravel's default pagination view (whose unstyled SVG
        // arrows rendered building-sized on this page).
        $response->assertSee('class="pagerRelative"', false);
        $response->assertDontSee('<svg', false);
    }

    public function test_the_timeline_screens_push_the_plugin_stylesheets_into_the_cascade(): void
    {
        $member = Member::factory()->create();
        TimelinePost::factory()->create(['member_id' => $member->getKey()]);

        $html = $this->actingAs($member)->get("/member/{$member->getKey()}/timeline")->assertOk()->getContent();

        // OpenPNE 3's use_stylesheet order: skin, then bootstrap, then timeline.css.
        $skin = strpos($html, 'opSkinBasicPlugin/css/main.css');
        $bootstrap = strpos($html, 'opTimelinePlugin/css/bootstrap.css');
        $timeline = strpos($html, 'opTimelinePlugin/css/timeline.css');
        $this->assertNotFalse($bootstrap);
        $this->assertNotFalse($timeline);
        $this->assertGreaterThan($skin, $bootstrap);
        $this->assertGreaterThan($bootstrap, $timeline);
    }

    public function test_a_page_without_timeline_rows_links_no_timeline_stylesheet(): void
    {
        // The push is component-driven: the home page without a timeline gadget stays clean.
        $this->actingAs(Member::factory()->create())->get('/')
            ->assertOk()
            ->assertDontSee('opTimelinePlugin', false);
    }

    public function test_a_home_timeline_gadget_pushes_the_stylesheets_once(): void
    {
        // Two timeline gadgets on one page still produce a single pair of links (@once).
        $this->makeHomeGadget('timelineAll');
        $this->makeHomeGadget('timelineFriend');
        $member = Member::factory()->create();
        TimelinePost::factory()->create(['member_id' => $member->getKey()]);

        $html = $this->actingAs($member)->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'opTimelinePlugin/css/timeline.css'));
        $this->assertSame(1, substr_count($html, 'opTimelinePlugin/css/bootstrap.css'));
    }
}

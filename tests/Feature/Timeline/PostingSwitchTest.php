<?php

namespace Tests\Feature\Timeline;

use App\Models\Gadget;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Services\GadgetService;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The switch closes every way in, on both surfaces, and leaves what is posted where it is; the
 * default-on side is pinned so the gate cannot quietly become the default.
 */
class PostingSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    private TimelinePost $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = Member::factory()->create();
        $this->post = TimelinePost::factory()->create(['member_id' => $this->member->getKey(), 'visibility' => Visibility::Members, 'body' => 'already here']);
    }

    public function test_off_answers_the_compose_page_and_both_write_routes_with_404(): void
    {
        $this->setSnsSetting(SnsSettingKey::TimelinePostingEnabled, false);

        $this->actingAs($this->member)->get(route('timeline.new'))->assertNotFound();
        $this->actingAs($this->member)->post(route('timeline.store'), ['body' => 'no', 'visibility' => Visibility::Members->value])->assertNotFound();
        $this->actingAs($this->member)->post(route('timeline.reply.store', $this->post), ['body' => 'no'])->assertNotFound();

        $this->assertDatabaseCount('timeline_posts', 1);
    }

    public function test_a_refused_post_consumes_no_posting_limiter(): void
    {
        // At one attempt per minute, a refusal that reached the throttle would 429 the member once the switch is back on.
        config(['openpne.throttle.posting' => 1, 'openpne.throttle.posting_ip' => 0]);
        $this->setSnsSetting(SnsSettingKey::TimelinePostingEnabled, false);

        $this->actingAs($this->member)->post(route('timeline.store'), ['body' => 'no', 'visibility' => Visibility::Members->value])->assertNotFound();
        $this->actingAs($this->member)->post(route('timeline.store'), ['body' => 'no', 'visibility' => Visibility::Members->value])->assertNotFound();

        $this->setSnsSetting(SnsSettingKey::TimelinePostingEnabled, true);

        $this->actingAs($this->member)->post(route('timeline.store'), ['body' => 'yes', 'visibility' => Visibility::Members->value])->assertRedirect();
        $this->assertDatabaseCount('timeline_posts', 2);
    }

    public function test_classic_rows_keep_a_well_formed_control_line_without_the_reply_link(): void
    {
        $other = TimelinePost::factory()->create(['member_id' => Member::factory()->create()->getKey(), 'visibility' => Visibility::Members, 'body' => 'theirs']);
        $this->setSnsSetting(SnsSettingKey::TimelinePostingEnabled, false);

        $html = $this->actingAs($this->member)->get(route('timeline.index'))->assertOk()->getContent();

        // The own row keeps "削除 | timestamp" while another member's row is the timestamp alone, with no leading separator.
        $this->assertStringContainsString('theirs', $html);
        $this->assertStringContainsString('| <a href="'.route('timeline.show', $this->post).'">', $html);
        $this->assertStringNotContainsString('| <a href="'.route('timeline.show', $other).'">', $html);
    }

    public function test_on_keeps_the_routes_open(): void
    {
        $this->actingAs($this->member)->get(route('timeline.new'))->assertOk();
        $this->actingAs($this->member)->post(route('timeline.store'), ['body' => 'yes', 'visibility' => Visibility::Members->value])->assertRedirect();

        $this->assertDatabaseCount('timeline_posts', 2);
    }

    public function test_classic_index_drops_the_compose_form_the_fallback_link_and_the_reply_forms(): void
    {
        $this->setSnsSetting(SnsSettingKey::TimelinePostingEnabled, false);

        $response = $this->actingAs($this->member)->get(route('timeline.index'))->assertOk();

        $response->assertSee('already here');
        $response->assertDontSee('data-timeline-compose', false);
        $response->assertDontSee('data-timeline-compose-fallback', false);
        $response->assertDontSee(route('timeline.new'), false);
        $response->assertDontSee('timeline-post-comment-form', false);
        $response->assertDontSee('#timeline-reply-form', false);
    }

    public function test_classic_load_more_rows_drop_their_reply_affordances_too(): void
    {
        $this->setSnsSetting(SnsSettingKey::TimelinePostingEnabled, false);

        $response = $this->actingAs($this->member)->get(route('timeline.index.rows', ['page' => 1]))->assertOk();

        $response->assertSee('already here');
        $response->assertDontSee('timeline-comment-link', false);
        $response->assertDontSee('timeline-post-comment-form', false);
    }

    public function test_classic_thread_page_drops_its_reply_form(): void
    {
        $this->setSnsSetting(SnsSettingKey::TimelinePostingEnabled, false);

        $response = $this->actingAs($this->member)->get(route('timeline.show', $this->post))->assertOk();

        $response->assertSee('already here');
        $response->assertDontSee('id="timeline-reply-form"', false);
        $response->assertDontSee(route('timeline.reply.store', $this->post), false);
    }

    public function test_classic_home_gadgets_drop_their_post_links(): void
    {
        $this->setSnsSetting(SnsSettingKey::TimelinePostingEnabled, false);
        foreach (['timelineAll', 'timelineFriend', 'activityBox', 'allMemberActivityBox'] as $index => $kind) {
            Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => $kind, 'sort_order' => $index]);
        }
        app(GadgetService::class)->clearCache();

        $response = $this->actingAs($this->member)->get(route('home'))->assertOk();

        $response->assertSee('already here');
        $response->assertDontSee(route('timeline.new'), false);
        $response->assertDontSee('data-timeline-compose', false);
    }

    public function test_an_activity_box_with_nothing_to_show_and_no_form_is_dropped_whole(): void
    {
        // OpenPNE 3 drew the box for activities or a form; off, with no activities, there is neither.
        $this->post->delete();
        foreach (['activityBox', 'allMemberActivityBox'] as $index => $kind) {
            Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => $kind, 'sort_order' => $index]);
        }
        app(GadgetService::class)->clearCache();

        $this->actingAs($this->member)->get(route('home'))->assertOk()->assertSee('activityBox homeRecentList', false);

        $this->setSnsSetting(SnsSettingKey::TimelinePostingEnabled, false);

        $this->actingAs($this->member)->get(route('home'))->assertOk()->assertDontSee('activityBox homeRecentList', false);
    }

    public function test_the_mention_picker_endpoint_closes_with_the_switch(): void
    {
        $this->actingAs($this->member)->get(route('timeline.mention_candidates', ['q' => 'a']))->assertOk();

        $this->setSnsSetting(SnsSettingKey::TimelinePostingEnabled, false);

        $this->actingAs($this->member)->get(route('timeline.mention_candidates', ['q' => 'a']))->assertNotFound();
    }

    public function test_modern_pages_carry_the_switch(): void
    {
        $this->setSnsSetting(SnsSettingKey::TimelinePostingEnabled, false);
        config(['openpne.surface_mode' => 'modern_default']);

        $this->actingAs($this->member)->get(route('timeline.index'))
            ->assertInertia(fn ($page) => $page->component('timeline/index')->where('canPost', false));
        $this->actingAs($this->member)->get(route('timeline.member', $this->member))
            ->assertInertia(fn ($page) => $page->component('timeline/member')->where('canPost', false));
        $this->actingAs($this->member)->get(route('timeline.show', $this->post))
            ->assertInertia(fn ($page) => $page->component('timeline/show')->where('canPost', false));
    }

    public function test_modern_pages_carry_the_default_on(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);

        $this->actingAs($this->member)->get(route('timeline.index'))
            ->assertInertia(fn ($page) => $page->where('canPost', true));
    }
}

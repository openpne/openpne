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
 * The OpenPNE 3 inline compose box, shipped hidden with a no-JS fallback link, and the
 * allowlisted return_to redirect contract of timeline.store.
 */
class TimelineComposeTest extends TestCase
{
    use RefreshDatabase;

    private function makeHomeGadget(string $name): void
    {
        Gadget::create(['context' => 'home', 'zone' => 'contents', 'name' => $name, 'sort_order' => 0]);
        app(GadgetService::class)->clearCache();
    }

    public function test_the_index_ships_the_hidden_form_and_the_fallback_link(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get(route('timeline.index'))->assertOk();

        // The form arrives hidden (the plugin CSS assumes the script) with the page's token, the
        // fallback <p> is marked for the script to retire, and the box exists with zero posts.
        $response->assertSee('data-timeline-compose hidden', false);
        $response->assertSee('name="return_to" value="index"', false);
        $response->assertSee('<p data-timeline-compose-fallback>', false);
        $response->assertSee(route('timeline.new'), false);
        $response->assertSee('js/classic-timeline-compose.js', false);
        // counter.css joins the cascade after timeline.css, only where the compose box renders.
        $html = $response->getContent();
        $this->assertGreaterThan(strpos($html, 'opTimelinePlugin/css/timeline.css'), strpos($html, 'opTimelinePlugin/css/counter.css'));
        // The compose box leads OpenPNE 3's .timeline shell.
        $response->assertSeeInOrder(['<div class="timeline">', 'data-timeline-compose', '<div id="timeline-list">'], false);
    }

    public function test_the_home_gadgets_share_one_script_and_one_counter_css(): void
    {
        $this->makeHomeGadget('timelineAll');
        $this->makeHomeGadget('timelineFriend');
        $member = Member::factory()->create();

        $html = $this->actingAs($member)->get('/')->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'data-timeline-compose hidden'));
        $this->assertSame(2, substr_count($html, 'name="return_to" value="home"'));
        $this->assertSame(1, substr_count($html, 'js/classic-timeline-compose.js'));
        $this->assertSame(1, substr_count($html, 'opTimelinePlugin/css/counter.css'));
    }

    public function test_the_member_timeline_and_show_render_no_compose_box(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);

        // OpenPNE 3 puts no compose form on the member timeline; the post link stays.
        $this->actingAs($member)->get("/member/{$member->getKey()}/timeline")->assertOk()
            ->assertDontSee('data-timeline-compose', false)
            ->assertDontSee('counter.css', false)
            ->assertSee(route('timeline.new'), false);
        $this->actingAs($member)->get(route('timeline.show', $post))->assertOk()
            ->assertDontSee('data-timeline-compose', false);
    }

    public function test_the_form_offers_the_shared_visibility_options_with_members_preselected(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get(route('timeline.index'))->assertOk();

        $response->assertSee('<option value="'.Visibility::Members->value.'" selected>', false);
        $response->assertSee('<option value="'.Visibility::Friends->value.'" >', false); // @selected(false) leaves the space
        $response->assertSee('<option value="'.Visibility::Private->value.'" >', false);
        // Open is admin-gated (off by default), matching the standalone form's option source.
        $response->assertDontSee('<option value="'.Visibility::Open->value.'"', false);
    }

    public function test_a_tokened_post_returns_to_its_page_never_the_referer(): void
    {
        $member = Member::factory()->create();

        // A hostile Referer must not steer the redirect; the token's route wins.
        $this->actingAs($member)
            ->from('https://evil.example/away')
            ->post(route('timeline.store'), ['body' => 'Inline', 'visibility' => Visibility::Members->value, 'return_to' => 'index'])
            ->assertRedirect(route('timeline.index'));

        $this->actingAs($member)
            ->post(route('timeline.store'), ['body' => 'From home', 'visibility' => Visibility::Members->value, 'return_to' => 'home'])
            ->assertRedirect(route('home'));

        // Posted from page 2, the redirect still lands on page 1, where the new post is.
        $this->actingAs($member)
            ->from(route('timeline.index').'?page=2')
            ->post(route('timeline.store'), ['body' => 'Paged', 'visibility' => Visibility::Members->value, 'return_to' => 'index'])
            ->assertRedirect(route('timeline.index'));
    }

    public function test_a_post_without_a_token_keeps_the_member_timeline_landing(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->post(route('timeline.store'), ['body' => 'Standalone', 'visibility' => Visibility::Members->value])
            ->assertRedirect(route('timeline.member', ['member' => $member->getKey()]));
    }

    public function test_an_invalid_token_fails_validation_without_leaving_the_site(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->from('https://evil.example/away')
            ->post(route('timeline.store'), ['body' => 'Bad token', 'visibility' => Visibility::Members->value, 'return_to' => 'https://evil.example'])
            ->assertRedirect(route('timeline.new'))
            ->assertSessionHasErrors('return_to');

        $this->assertDatabaseMissing('timeline_posts', ['body' => 'Bad token']);
    }

    public function test_an_array_token_fails_validation_instead_of_erroring(): void
    {
        $member = Member::factory()->create();

        // return_to[]=… must not reach the route lookup as an array (a 500), nor the Referer.
        $this->actingAs($member)
            ->from('https://evil.example/away')
            ->post(route('timeline.store'), ['body' => 'Array token', 'visibility' => Visibility::Members->value, 'return_to' => ['index']])
            ->assertRedirect(route('timeline.new'))
            ->assertSessionHasErrors('return_to');

        $this->assertDatabaseMissing('timeline_posts', ['body' => 'Array token']);
    }

    public function test_a_failed_inline_post_returns_to_its_page_with_the_draft(): void
    {
        $member = Member::factory()->create();

        // Validation failure with a token lands back on the token's page (not the Referer), and
        // the reloaded form shows the draft and the reason in OpenPNE 3's error seam.
        $this->actingAs($member)
            ->from('https://evil.example/away')
            ->post(route('timeline.store'), ['body' => 'A kept draft', 'visibility' => 99, 'return_to' => 'index'])
            ->assertRedirect(route('timeline.index'));

        $this->actingAs($member)->get(route('timeline.index'))->assertOk()
            ->assertSee('>A kept draft</textarea>', false)
            ->assertSee('id="timeline-submit-error"', false);
    }

    public function test_a_failed_standalone_post_returns_to_the_compose_page(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->from('https://evil.example/away')
            ->post(route('timeline.store'), ['body' => '', 'visibility' => Visibility::Members->value])
            ->assertRedirect(route('timeline.new'));
    }
}

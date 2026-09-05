<?php

namespace Tests\Feature\Timeline\Classic;

use App\Features\Timeline\Queries\RecentReplies;
use App\Files\ImageTransform;
use App\Models\File;
use App\Models\Gadget;
use App\Models\Member;
use App\Models\MemberImage;
use App\Models\TimelinePost;
use App\Models\TimelinePostImage;
use App\Services\GadgetService;
use App\Support\LocalizedDate;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The expected DOM is OpenPNE 3's timelineTemplate. */
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
        $response->assertSeeInOrder(['<div class="timeline" data-timeline-container>', '<div id="timeline-list">'], false);
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
        // carries the same link as a same-page jump, inside OpenPNE 3's show shell.
        $this->actingAs($member)->get(route('timeline.show', $post))->assertOk()
            ->assertSeeInOrder([
                '<div class="timeline-large">',
                '<div id="timeline-list">',
                '<div class="timeline-post" data-timeline-id="'.$post->getKey().'">',
                'id="timeline-reply-form"',
                'aria-label="Post comment"', // the OpenPNE 3 skin gave the textarea no label; a name is the floor
            ], false);
    }

    public function test_the_row_carries_the_op3_inline_comment_block(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);
        TimelinePost::factory()->replyTo($post)->create(['member_id' => $member->getKey(), 'body' => 'An inline answer']);

        $response = $this->actingAs($member)->get(route('timeline.index'))->assertOk();

        // timelineTemplate's comment block, in its order: the reply rows, then the form the script
        // reveals, then OpenPNE 3's loader and error seams.
        $response->assertSeeInOrder([
            '<div class="timeline-post-comments" id="commentlist-'.$post->getKey().'">',
            '<div class="timeline-post-comment" data-timeline-id=',
            'An inline answer',
            '<form method="POST" action="'.route('timeline.reply.store', $post).'"',
            'id="timeline-post-comment-form-'.$post->getKey().'" class="timeline-post-comment-form"',
            'id="comment-textarea-'.$post->getKey().'"',
            'id="timeline-post-comment-form-loader-'.$post->getKey().'"',
            'id="timeline-post-comment-form-error-'.$post->getKey().'"',
        ], false);
        // The form posts for real, and the script that enhances it loads once from the page.
        $response->assertSee('data-timeline-reply', false);
        $response->assertSee('js/classic-timeline-replies.js', false);
    }

    public function test_the_load_more_control_renders_only_past_the_tail_and_carries_its_url(): void
    {
        $member = Member::factory()->create();
        $short = TimelinePost::factory()->create(['member_id' => $member->getKey()]);
        TimelinePost::factory()->count(RecentReplies::LIMIT)->replyTo($short)->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)->get(route('timeline.index'))->assertOk()
            ->assertDontSee('timeline-comment-loadmore', false);

        TimelinePost::factory()->replyTo($short)->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)->get(route('timeline.index'))->assertOk()
            ->assertSee('id="timeline-comment-loadmore-'.$short->getKey().'"', false)
            ->assertSee('data-replies-url="'.route('timeline.replies', $short).'"', false)
            ->assertSee('id="timeline-comment-loader-'.$short->getKey().'"', false)
            // OpenPNE 3 left it a bare anchor; ours goes to the thread, which is the same list.
            ->assertSee('class="timeline-comment-loadmore" href="'.route('timeline.show', $short).'"', false);
    }

    public function test_the_thread_row_holds_the_whole_thread_and_no_inline_form(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);
        foreach (range(1, RecentReplies::LIMIT + 2) as $i) {
            TimelinePost::factory()->replyTo($post)->create(['member_id' => $member->getKey(), 'body' => "Answer {$i}"]);
        }

        $response = $this->actingAs($member)->get(route('timeline.show', $post))->assertOk();

        // Every reply, including the ones a feed row's tail leaves out — so there is nothing to
        // load more of, and the page's own reply form is the one place to write.
        $this->assertSame(
            RecentReplies::LIMIT + 2,
            substr_count($response->getContent(), '<div class="timeline-post-comment" data-timeline-id=')
        );
        $response->assertSee('Answer 1');
        $response->assertDontSee('data-timeline-reply', false);
        $response->assertDontSee('timeline-comment-loadmore', false);
        // The OpenPNE-4-only list this replaced is gone.
        $response->assertDontSee('<ul class="timeline-comment-list">', false);
    }

    public function test_a_reply_row_follows_the_op3_comment_template(): void
    {
        [$viewer, $author] = Member::factory()->count(2)->create()->all();
        MemberImage::factory()->create([
            'member_id' => $viewer->getKey(),
            'file_id' => File::factory()->create(['type' => 'image/png', 'width' => 200, 'height' => 200])->getKey(),
        ]);
        // Someone else's thread, so the only delete control on the page is the viewer's own reply.
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey()]);
        $own = TimelinePost::factory()->replyTo($post)->create(['member_id' => $viewer->getKey()]);
        TimelinePost::factory()->replyTo($post)->create(['member_id' => $author->getKey()]);

        $response = $this->actingAs($viewer)->get(route('timeline.show', $post))->assertOk();

        // timelineCommentTemplate: a 36px avatar block, then the name and body inline, then the
        // control line — delete on the viewer's own reply only.
        $response->assertSeeInOrder([
            '<div class="timeline-post-comment-member-image">',
            'width="36" height="36"',
            '<div class="timeline-post-comment-content">',
            '<div class="timeline-post-comment-name-and-body">',
            '<span class="timeline-post-comment-body">',
            '<div class="timeline-post-comment-control">',
        ], false);
        $response->assertSee('href="'.route('timeline.delete.show', $own).'"', false);
        $this->assertSame(1, substr_count($response->getContent(), 'timeline-post-delete-confirm-link'));

        // OpenPNE 3 served the 48px thumbnail and drew it at 36: 36 is not a thumbnail size, and a
        // request for one is a 404 behind a broken image on every row with an avatar.
        $this->assertSame(1, preg_match('#/cache/img/\w+/(w\d+_h\d+_sq)/#', $response->getContent(), $m));
        $this->assertSame('w48_h48_sq', $m[1]);
        $this->assertNotNull(ImageTransform::fromGeometry($m[1]));
    }

    public function test_own_rows_carry_the_delete_dialog_and_only_the_thread_root_posts_as_a_page(): void
    {
        $viewer = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $viewer->getKey()]);
        $reply = TimelinePost::factory()->replyTo($post)->create(['member_id' => $viewer->getKey()]);

        $feed = $this->actingAs($viewer)->get(route('timeline.index'))->assertOk();
        $feed->assertSee('data-dialog="timeline-post-delete-confirm-'.$post->getKey().'"', false);
        $feed->assertSee('<dialog class="timeline-post-delete-dialog" id="timeline-post-delete-confirm-'.$post->getKey().'"', false);
        $feed->assertSee('<dialog class="timeline-post-delete-dialog" id="timeline-post-delete-confirm-'.$reply->getKey().'"', false);
        // The feed row and its reply both leave the page on the JSON answer.
        $this->assertSame(2, substr_count($feed->getContent(), ' data-timeline-delete '));

        // On its own page the thread root posts as the page: the page is what goes.
        $show = $this->actingAs($viewer)->get(route('timeline.show', $post))->assertOk();
        $show->assertSee('id="timeline-post-delete-confirm-'.$post->getKey().'"', false);
        $this->assertSame(1, substr_count($show->getContent(), ' data-timeline-delete '));
        $this->assertSame(1, preg_match('#id="timeline-post-delete-confirm-'.$reply->getKey().'".*?data-timeline-delete#s', $show->getContent()));
        $this->assertSame(0, preg_match('#action="'.preg_quote(route('timeline.delete', $post), '#').'" data-timeline-delete#', $show->getContent()));

        // Someone else's row carries neither the link nor the dialog.
        $other = Member::factory()->create();
        $this->actingAs($other)->get(route('timeline.index'))->assertOk()
            ->assertDontSee('timeline-post-delete-dialog', false);
    }

    public function test_the_timestamp_carries_the_machine_value_and_the_words_it_shows(): void
    {
        $viewer = Member::factory()->create(['locale' => 'ja']);
        $post = TimelinePost::factory()->create(['member_id' => $viewer->getKey(), 'created_at' => '2026-06-04 13:44:00']);

        $absolute = LocalizedDate::dateTime($post->created_at);
        $this->actingAs($viewer)->get(route('timeline.index'))->assertOk()
            ->assertSee('<span class="timestamp timeago" title="'.$absolute.'" data-datetime="'.$post->created_at->toIso8601String().'">'.$absolute.'</span>', false);
    }

    public function test_an_attached_image_sits_in_the_lightbox_link_to_its_file(): void
    {
        $viewer = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $viewer->getKey()]);
        $image = TimelinePostImage::factory()->create(['timeline_post_id' => $post->getKey()]);

        $this->actingAs($viewer)->get(route('timeline.index'))->assertOk()
            ->assertSee('<a href="'.$image->file->url().'" rel="lightbox"><div><img class="timeline-post-image" src="'.$image->file->thumbnailUrl(120, 120, square: true).'" alt=""></div></a>', false);
    }

    public function test_the_profile_gadget_pushes_css_only_when_it_renders(): void
    {
        $owner = Member::factory()->create();
        Gadget::create(['context' => 'profile', 'zone' => 'contents', 'name' => 'timelineProfile', 'sort_order' => 0]);
        app(GadgetService::class)->clearCache();

        // The owner's own empty timeline still renders the box (post link), so the CSS loads;
        // someone else's empty timeline drops the box and the CSS with it.
        $this->actingAs($owner)->get('/member/'.$owner->getKey())->assertOk()
            ->assertSee('opTimelinePlugin/css/timeline.css', false);
        $this->actingAs(Member::factory()->create())->get('/member/'.$owner->getKey())->assertOk()
            ->assertDontSee('opTimelinePlugin', false);
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

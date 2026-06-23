<?php

namespace Tests\Feature\Timeline\Classic;

use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimelineReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $post = TimelinePost::factory()->create(['member_id' => Member::factory()->create()->getKey()]);

        $this->post("/timeline/{$post->getKey()}/reply")->assertRedirect('/login');
    }

    public function test_show_renders_the_thread_and_reply_form(): void
    {
        $author = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);
        TimelinePost::factory()->replyTo($post)->create(['member_id' => $author->getKey(), 'body' => 'A reply here']);

        $response = $this->actingAs($author)->get("/timeline/{$post->getKey()}");

        $response->assertOk();
        $response->assertSee('A reply here');
        $response->assertSee(route('timeline.reply.store', $post), false);
    }

    public function test_a_viewer_can_post_a_reply(): void
    {
        [$author, $viewer] = Member::factory()->count(2)->create()->all();
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);

        $this->actingAs($viewer)
            ->post("/timeline/{$post->getKey()}/reply", ['body' => 'My reply'])
            ->assertRedirect("/timeline/{$post->getKey()}");

        $this->assertDatabaseHas('timeline_posts', [
            'member_id' => $viewer->getKey(),
            'in_reply_to_id' => $post->getKey(),
            'body' => 'My reply',
            'visibility' => Visibility::Members->value,
        ]);
    }

    public function test_reply_requires_viewing_the_post(): void
    {
        [$author, $viewer] = Member::factory()->count(2)->create()->all();
        $post = TimelinePost::factory()->private()->create(['member_id' => $author->getKey()]);

        $this->actingAs($viewer)->post("/timeline/{$post->getKey()}/reply", ['body' => 'sneaky'])->assertNotFound();
        $this->assertDatabaseCount('timeline_posts', 1);
    }

    public function test_reply_body_is_required_and_capped_at_140(): void
    {
        $author = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);

        $this->actingAs($author)
            ->post("/timeline/{$post->getKey()}/reply", ['body' => str_repeat('あ', 141)])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('timeline_posts', 1);
    }

    public function test_reply_permalink_re_centers_to_the_thread_root(): void
    {
        // OpenPNE 3 opens the thread at the top-level post; a reply's permalink redirects there.
        $author = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);
        $reply = TimelinePost::factory()->replyTo($post)->create(['member_id' => $author->getKey()]);

        $this->actingAs($author)->get("/timeline/{$reply->getKey()}")->assertRedirect("/timeline/{$post->getKey()}");
    }

    public function test_author_deletes_their_reply_and_returns_to_the_thread(): void
    {
        [$author, $replier] = Member::factory()->count(2)->create()->all();
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);
        $reply = TimelinePost::factory()->replyTo($post)->create(['member_id' => $replier->getKey()]);

        $this->actingAs($replier)
            ->post("/timeline/delete/{$reply->getKey()}")
            ->assertRedirect("/timeline/{$post->getKey()}");

        $this->assertDatabaseMissing('timeline_posts', ['id' => $reply->getKey()]);
        $this->assertDatabaseHas('timeline_posts', ['id' => $post->getKey()]);
    }

    public function test_non_author_cannot_delete_a_reply(): void
    {
        [$author, $replier, $other] = Member::factory()->count(3)->create()->all();
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);
        $reply = TimelinePost::factory()->replyTo($post)->create(['member_id' => $replier->getKey()]);

        $this->actingAs($other)->post("/timeline/delete/{$reply->getKey()}")->assertNotFound();
        $this->assertDatabaseHas('timeline_posts', ['id' => $reply->getKey()]);
    }

    public function test_deleting_a_top_level_post_cascades_its_replies(): void
    {
        $author = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);
        $reply = TimelinePost::factory()->replyTo($post)->create(['member_id' => $author->getKey()]);

        $this->actingAs($author)
            ->post("/timeline/delete/{$post->getKey()}")
            ->assertRedirect(route('timeline.member', $author));

        $this->assertDatabaseMissing('timeline_posts', ['id' => $post->getKey()]);
        $this->assertDatabaseMissing('timeline_posts', ['id' => $reply->getKey()]);
    }
}

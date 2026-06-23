<?php

namespace Tests\Feature\Timeline\Classic;

use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimelinePostWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/timeline/new')->assertRedirect('/login');
        $this->post('/timeline/create')->assertRedirect('/login');
    }

    public function test_compose_page_renders_the_post_form(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/timeline/new');

        $response->assertOk();
        $response->assertSee('name="body"', false);
        $response->assertSee(route('timeline.store'), false);
    }

    public function test_store_creates_a_post_and_redirects_to_the_member_timeline(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->post('/timeline/create', [
            'body' => 'Hello',
            'visibility' => (string) Visibility::Members->value,
        ]);

        $response->assertRedirect(route('timeline.member', $member));
        $this->assertDatabaseHas('timeline_posts', [
            'member_id' => $member->getKey(),
            'body' => 'Hello',
            'in_reply_to_id' => null,
        ]);
    }

    public function test_store_rejects_a_body_over_140_characters(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/timeline/create', [
            'body' => str_repeat('あ', 141),
            'visibility' => (string) Visibility::Members->value,
        ])->assertSessionHasErrors('body');

        $this->assertDatabaseCount('timeline_posts', 0);
    }

    public function test_delete_confirm_404_for_non_author(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        $post = TimelinePost::factory()->create(['member_id' => $owner->getKey()]);

        $this->actingAs($other)->get("/timeline/deleteConfirm/{$post->getKey()}")->assertNotFound();
    }

    public function test_owner_deletes_their_post_and_is_redirected(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)->post("/timeline/delete/{$post->getKey()}")
            ->assertRedirect(route('timeline.member', $member));

        $this->assertDatabaseMissing('timeline_posts', ['id' => $post->getKey()]);
    }

    public function test_non_author_cannot_delete(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        $post = TimelinePost::factory()->create(['member_id' => $owner->getKey()]);

        $this->actingAs($other)->post("/timeline/delete/{$post->getKey()}")->assertNotFound();
        $this->assertDatabaseHas('timeline_posts', ['id' => $post->getKey()]);
    }
}

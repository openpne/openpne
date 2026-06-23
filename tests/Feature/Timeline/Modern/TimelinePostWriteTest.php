<?php

namespace Tests\Feature\Timeline\Modern;

use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimelinePostWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_modern_compose_renders_inertia_component(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get('/m/timeline/new')
            ->assertInertia(fn ($page) => $page->component('timeline/new'));
    }

    public function test_modern_store_creates_a_post_and_redirects_to_modern_member(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->post('/m/timeline/create', [
            'body' => 'Modern post',
            'visibility' => (string) Visibility::Members->value,
        ]);

        $response->assertRedirect(route('timeline.modern.member', $member));
        $this->assertDatabaseHas('timeline_posts', ['body' => 'Modern post']);
    }

    public function test_modern_delete_confirm_renders_inertia_component(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)
            ->get("/m/timeline/deleteConfirm/{$post->getKey()}")
            ->assertInertia(fn ($page) => $page->component('timeline/delete')->where('post.id', $post->getKey()));
    }

    public function test_modern_delete_removes_the_post(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)->post("/m/timeline/delete/{$post->getKey()}")
            ->assertRedirect(route('timeline.modern.member', $member));

        $this->assertDatabaseMissing('timeline_posts', ['id' => $post->getKey()]);
    }
}

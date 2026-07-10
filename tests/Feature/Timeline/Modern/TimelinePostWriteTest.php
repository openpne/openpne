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

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    public function test_modern_compose_renders_inertia_component(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get('/timeline/new')
            ->assertInertia(fn ($page) => $page->component('timeline/new'));
    }

    public function test_modern_store_creates_a_post_and_redirects_to_member_timeline(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->post('/timeline/create', [
            'body' => 'Modern post',
            'visibility' => (string) Visibility::Members->value,
        ]);

        $response->assertRedirect(route('timeline.member', $member));
        $this->assertDatabaseHas('timeline_posts', ['body' => 'Modern post']);
    }

    public function test_modern_delete_returns_404_for_a_non_author(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);

        $this->actingAs(Member::factory()->create())
            ->post("/timeline/delete/{$post->getKey()}")
            ->assertNotFound();
        $this->assertDatabaseHas('timeline_posts', ['id' => $post->getKey()]);
    }

    public function test_modern_delete_removes_the_post(): void
    {
        $member = Member::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);

        $this->actingAs($member)->post("/timeline/delete/{$post->getKey()}")
            ->assertRedirect(route('timeline.member', $member));

        $this->assertDatabaseMissing('timeline_posts', ['id' => $post->getKey()]);
    }
}

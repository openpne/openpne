<?php

namespace Tests\Feature\Home;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Diary;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_dashboard_renders_inertia_with_the_three_digests(): void
    {
        $viewer = Member::factory()->create();
        TimelinePost::factory()->create(['member_id' => $viewer->getKey(), 'visibility' => Visibility::Members]);
        Diary::factory()->create(['visibility' => Visibility::Members]);
        $community = Community::factory()->create();
        CommunityMember::factory()->member()->create(['community_id' => $community->getKey(), 'member_id' => $viewer->getKey()]);

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->has('timeline', 1)
                ->has('diaries', 1)
                ->has('communities', 1)
                ->where('communities.0.id', $community->getKey())
            );
    }

    public function test_each_digest_is_capped_to_the_preview_size(): void
    {
        $viewer = Member::factory()->create();
        Diary::factory()->count(6)->create(['visibility' => Visibility::Members]);

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page->component('dashboard')->has('diaries', 5));
    }

    public function test_dashboard_renders_inertia_under_modern_only(): void
    {
        config()->set('openpne.tenant_mode', 'modern_only');
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->has('timeline')
                ->has('diaries')
                ->has('communities')
            );
    }
}

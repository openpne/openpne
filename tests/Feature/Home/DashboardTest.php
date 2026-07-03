<?php

namespace Tests\Feature\Home;

use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\CommunityTopic;
use App\Models\Diary;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_dashboard_renders_the_four_digests(): void
    {
        $viewer = Member::factory()->create();
        TimelinePost::factory()->create(['member_id' => $viewer->getKey(), 'visibility' => Visibility::Members]);
        Diary::factory()->create(['visibility' => Visibility::Members]);           // all-members feed
        Diary::factory()->create(['member_id' => $viewer->getKey(), 'visibility' => Visibility::Private]); // own
        $community = Community::factory()->create();
        CommunityMember::factory()->member()->create(['community_id' => $community->getKey(), 'member_id' => $viewer->getKey()]);
        $topic = CommunityTopic::factory()->create(['community_id' => $community->getKey()]);

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->has('diaries')
                ->has('timeline', 1)
                ->has('communityActivity', 1)
                ->where('communityActivity.0.kind', 'topic')
                ->where('communityActivity.0.id', $topic->getKey())
                ->has('myDiaries', 1)
                ->missing('communities')
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

    public function test_carries_author_avatars_without_an_n_plus_1(): void
    {
        $viewer = Member::factory()->create();
        // Several diaries and timeline posts, each by a distinct author, so a lazy avatar load would
        // scale with the row count.
        foreach (Member::factory()->count(4)->create() as $author) {
            Diary::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);
            TimelinePost::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Open]);
        }

        DB::enableQueryLog();
        $this->actingAs($viewer)->get('/dashboard')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Bounded by the number of feeds + their eager loads, not by the number of rows.
        $this->assertLessThan(40, $queries, "dashboard ran {$queries} queries — an author avatar is likely lazy-loading");
    }

    public function test_dashboard_renders_inertia_under_modern_only(): void
    {
        config()->set('openpne.surface_mode', 'modern_only');
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->component('dashboard')
                ->has('diaries')
                ->has('timeline')
                ->has('communityActivity')
                ->has('myDiaries')
            );
    }
}

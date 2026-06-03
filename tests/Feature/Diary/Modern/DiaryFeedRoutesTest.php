<?php

namespace Tests\Feature\Diary\Modern;

use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiaryFeedRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/m/diary/list')->assertRedirect('/login');
        $this->get('/m/diary/listFriend')->assertRedirect('/login');
    }

    public function test_recent_feed_renders_inertia_with_all_scope(): void
    {
        $viewer = Member::factory()->create();
        $author = Member::factory()->create(['name' => 'Author']);
        Diary::factory()->create([
            'member_id' => $author->getKey(),
            'title' => 'Hello world',
            'visibility' => Visibility::Members,
        ]);

        $this->actingAs($viewer)->get('/m/diary/list')
            ->assertInertia(fn ($page) => $page
                ->component('diary/feed')
                ->where('scope', 'all')
                ->has('diaries.data', 1)
                ->where('diaries.data.0.title', 'Hello world')
                ->where('diaries.data.0.author.name', 'Author')
            );
    }

    public function test_friend_feed_renders_inertia_with_friends_scope(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        DB::table('friendships')->insert([
            ['member_id' => $viewer->getKey(), 'friend_id' => $friend->getKey()],
            ['member_id' => $friend->getKey(), 'friend_id' => $viewer->getKey()],
        ]);
        Diary::factory()->create([
            'member_id' => $friend->getKey(),
            'title' => 'Friend entry',
            'visibility' => Visibility::Friends,
        ]);

        $this->actingAs($viewer)->get('/m/diary/listFriend')
            ->assertInertia(fn ($page) => $page
                ->component('diary/feed')
                ->where('scope', 'friends')
                ->has('diaries.data', 1)
                ->where('diaries.data.0.title', 'Friend entry')
            );
    }

    public function test_recent_feed_excludes_a_blocking_owners_diary(): void
    {
        $viewer = Member::factory()->create();
        $blocker = Member::factory()->create();
        Diary::factory()->create([
            'member_id' => $blocker->getKey(),
            'visibility' => Visibility::Members,
        ]);
        DB::table('member_blocks')->insert([
            'blocker_id' => $blocker->getKey(),
            'blocked_id' => $viewer->getKey(),
        ]);

        $this->actingAs($viewer)->get('/m/diary/list')
            ->assertInertia(fn ($page) => $page->has('diaries.data', 0));
    }
}

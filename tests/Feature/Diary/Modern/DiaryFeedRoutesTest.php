<?php

namespace Tests\Feature\Diary\Modern;

use App\Models\Diary;
use App\Models\DiaryImage;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiaryFeedRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.surface_mode' => 'modern_default']);
    }

    public function test_the_friend_feed_still_redirects_a_guest_to_login(): void
    {
        $this->get('/diary/listFriend')->assertRedirect('/login');
    }

    public function test_a_guest_gets_the_feed_limited_to_web_public_entries(): void
    {
        Diary::factory()->create(['title' => 'Open note', 'visibility' => Visibility::Open]);
        Diary::factory()->create(['title' => 'Members note', 'visibility' => Visibility::Members]);

        $this->get('/diary/list')->assertInertia(fn ($page) => $page
            ->component('diary/feed')
            ->has('diaries.data', 1)
            ->where('diaries.data.0.title', 'Open note')
        );
    }

    public function test_recent_feed_renders_inertia_with_recent_variant(): void
    {
        $viewer = Member::factory()->create();
        $author = Member::factory()->create(['name' => 'Author']);
        Diary::factory()->create([
            'member_id' => $author->getKey(),
            'title' => 'Hello world',
            'visibility' => Visibility::Members,
        ]);

        $this->actingAs($viewer)->get('/diary/list')
            ->assertInertia(fn ($page) => $page
                ->component('diary/feed')
                ->where('variant', 'recent')
                ->has('diaries.data', 1)
                ->where('diaries.data.0.title', 'Hello world')
                ->where('diaries.data.0.author.name', 'Author')
            );
    }

    public function test_friend_feed_renders_inertia_with_friends_variant(): void
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

        $this->actingAs($viewer)->get('/diary/listFriend')
            ->assertInertia(fn ($page) => $page
                ->component('diary/feed')
                ->where('variant', 'friends')
                ->has('diaries.data', 1)
                ->where('diaries.data.0.title', 'Friend entry')
            );
    }

    public function test_rich_feed_row_carries_the_excerpt_and_thumbnails(): void
    {
        $viewer = Member::factory()->create();
        $author = Member::factory()->create();
        $diary = Diary::factory()->create([
            'member_id' => $author->getKey(),
            'body' => "Lead line\nmore body",
            'visibility' => Visibility::Members,
        ]);
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'number' => 1]);

        // A non-empty thumbnails array proves the Modern closure ran loadMissing('images.file'):
        // without it the relation is unloaded and the serializer returns [].
        $this->actingAs($viewer)->get('/diary/list')
            ->assertInertia(fn ($page) => $page
                ->where('diaries.data.0.excerpt', 'Lead line more body')
                ->has('diaries.data.0.thumbnails', 1)
                ->where('diaries.data.0.thumbnails.0', fn ($url) => is_string($url) && $url !== '')
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

        $this->actingAs($viewer)->get('/diary/list')
            ->assertInertia(fn ($page) => $page->has('diaries.data', 0));
    }
}

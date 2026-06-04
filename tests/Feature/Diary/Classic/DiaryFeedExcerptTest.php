<?php

namespace Tests\Feature\Diary\Classic;

use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Locks the OpenPNE 3 listSuccess body excerpt: the recent and search feeds preview each diary
 * body (HTML-escaped), while the friends feed (listFriendSuccess) shows no body.
 */
class DiaryFeedExcerptTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_feed_shows_the_body_excerpt(): void
    {
        $member = Member::factory()->create();
        Diary::factory()->create([
            'member_id' => $member->getKey(),
            'visibility' => Visibility::Members,
            'body' => 'A memorable diary body excerpt.',
        ]);

        $this->actingAs($member)->get('/diary/list')
            ->assertOk()
            ->assertSee('A memorable diary body excerpt.');
    }

    public function test_recent_feed_excerpt_escapes_html(): void
    {
        $member = Member::factory()->create();
        Diary::factory()->create([
            'member_id' => $member->getKey(),
            'visibility' => Visibility::Members,
            'body' => '<script>alert(1)</script>',
        ]);

        $this->actingAs($member)->get('/diary/list')
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_friend_feed_omits_the_body_excerpt(): void
    {
        [$viewer, $friend] = Member::factory()->count(2)->create()->all();
        DB::table('friendships')->insert([
            ['member_id' => $viewer->getKey(), 'friend_id' => $friend->getKey()],
            ['member_id' => $friend->getKey(), 'friend_id' => $viewer->getKey()],
        ]);
        Diary::factory()->create([
            'member_id' => $friend->getKey(),
            'title' => 'Friend Entry',
            'visibility' => Visibility::Friends,
            'body' => 'Secret friend body text.',
        ]);

        $this->actingAs($viewer)->get('/diary/listFriend')
            ->assertOk()
            ->assertSee('Friend Entry')
            ->assertDontSee('Secret friend body text.');
    }
}

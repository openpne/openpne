<?php

namespace Tests\Feature\Home;

use App\Features\Home\UnreadCounts;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnreadCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_aggregates_friend_requests_and_unread_messages(): void
    {
        [$viewer, $sender] = Member::factory()->count(2)->create()->all();
        DB::table('friend_requests')->insert(['requester_id' => $sender->getKey(), 'target_id' => $viewer->getKey()]);
        $message = Message::factory()->create(['sender_id' => $sender->getKey()]);
        MessageRecipient::factory()->create(['message_id' => $message->getKey(), 'recipient_id' => $viewer->getKey()]);

        $counts = app(UnreadCounts::class)->for($viewer);

        $this->assertSame(['friendRequests' => 1, 'unreadMessages' => 1], $counts);
    }

    public function test_memoizes_per_member_within_the_request(): void
    {
        $viewer = Member::factory()->create();
        $service = app(UnreadCounts::class);

        DB::enableQueryLog();
        $service->for($viewer);
        $first = count(DB::getQueryLog());
        $service->for($viewer);
        $second = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($first, $second, 'the second call must not re-query');
    }

    public function test_is_shared_to_inertia_pages_for_a_signed_in_member(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('unread.friendRequests', 0)
                ->where('unread.unreadMessages', 0)
            );
    }
}

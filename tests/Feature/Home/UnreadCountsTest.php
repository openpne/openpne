<?php

namespace Tests\Feature\Home;

use App\Features\Home\UnreadCounts;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Support\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnreadCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_aggregates_friend_requests_unread_messages_and_notifications(): void
    {
        [$viewer, $sender] = Member::factory()->count(2)->create()->all();
        DB::table('friend_requests')->insert(['requester_id' => $sender->getKey(), 'target_id' => $viewer->getKey()]);
        $message = Message::factory()->create(['sender_id' => $sender->getKey()]);
        MessageRecipient::factory()->create(['message_id' => $message->getKey(), 'recipient_id' => $viewer->getKey()]);
        $viewer->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'data' => ['kind' => 'friend_requested', 'requester_id' => $sender->getKey()],
        ]);

        $counts = app(UnreadCounts::class)->for($viewer);

        $this->assertSame(['friendRequests' => 1, 'unreadMessages' => 1, 'notifications' => 1], $counts);
    }

    public function test_a_switched_off_unit_reports_zero_without_querying(): void
    {
        // Rows that would count, so the zero proves the skip rather than an empty database.
        [$viewer, $sender] = Member::factory()->count(2)->create()->all();
        DB::table('friend_requests')->insert(['requester_id' => $sender->getKey(), 'target_id' => $viewer->getKey()]);
        $message = Message::factory()->create(['sender_id' => $sender->getKey()]);
        MessageRecipient::factory()->create(['message_id' => $message->getKey(), 'recipient_id' => $viewer->getKey()]);

        $this->setSnsSetting(Feature::Friend->settingKey(), false);
        $this->setSnsSetting(Feature::Message->settingKey(), false);

        DB::enableQueryLog();
        $counts = app(UnreadCounts::class)->for($viewer);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(0, $counts['friendRequests']);
        $this->assertSame(0, $counts['unreadMessages']);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('friend_requests', $query['query']);
            $this->assertStringNotContainsString('message_recipients', $query['query']);
        }
    }

    public function test_the_notification_count_ignores_the_other_units_toggles(): void
    {
        $viewer = Member::factory()->create();
        $viewer->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'data' => ['kind' => 'friend_requested'],
        ]);

        $this->setSnsSetting(Feature::Friend->settingKey(), false);
        $this->setSnsSetting(Feature::Message->settingKey(), false);

        $this->assertSame(1, app(UnreadCounts::class)->for($viewer)['notifications']);
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

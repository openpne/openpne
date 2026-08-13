<?php

namespace Tests\Feature\Home;

use App\Models\Member;
use App\Support\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The JSON counts an open Modern tab polls. Same numbers as the shared `unread` prop, on their own.
 */
class UnreadCountsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_viewers_counts(): void
    {
        $viewer = Member::factory()->create();
        $viewer->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'data' => ['kind' => 'friend_requested'],
        ]);

        $this->actingAs($viewer)
            ->getJson('/unread-counts')
            ->assertOk()
            ->assertExactJson(['friendRequests' => 0, 'unreadMessages' => 0, 'notifications' => 1, 'groupTalks' => 0]);
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/unread-counts')->assertRedirect('/login');
    }

    public function test_an_expired_session_gets_a_401_not_a_redirect(): void
    {
        // The real client asks with Accept: application/json (unread-sync.tsx), so this — not the
        // redirect above — is what a poll sees when its session dies; it stays silent on it.
        $this->getJson('/unread-counts')->assertUnauthorized();
    }

    public function test_a_switched_off_unit_reports_zero(): void
    {
        [$viewer, $sender] = Member::factory()->count(2)->create()->all();
        DB::table('friend_requests')->insert(['requester_id' => $sender->getKey(), 'target_id' => $viewer->getKey()]);

        $this->setSnsSetting(Feature::Friend->settingKey(), false);

        $this->actingAs($viewer)
            ->getJson('/unread-counts')
            ->assertOk()
            ->assertJsonPath('friendRequests', 0);
    }
}

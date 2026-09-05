<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications\Classic;

use App\Features\Notifications\NotificationCenterWindow;
use App\Models\Member;
use App\Notifications\Diary\DiaryCommentedNotification;
use App\Notifications\Friend\FriendRequestedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationCenterPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_row_that_leads_somewhere_posts_to_the_open_action(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $row = $this->seedDiaryComment($viewer, $actor);

        $response = $this->actingAs($viewer)->get(route('notifications.center'))->assertOk();

        $response->assertSee('class="push nclink"', false)
            ->assertSee('action="'.e(route('notifications.open', $row)).'"', false)
            ->assertSee('name="_token"', false);
    }

    public function test_the_counts_answer_the_badges_by_id_and_uncached(): void
    {
        $this->get(route('notifications.center.counts'))->assertRedirect('/login'); // before actingAs: it persists

        [$viewer, $actor, $requester] = Member::factory()->count(3)->create()->all();
        $this->seedDiaryComment($viewer, $actor);
        $this->seedFriendRequest($viewer, $requester);

        $this->actingAs($viewer)->getJson(route('notifications.center.counts'))
            ->assertOk()
            ->assertExactJson(['badges' => ['nc_icon1' => 0, 'nc_icon2' => 1, 'nc_icon3' => 1]])
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->actingAs($viewer)->get('/')->assertOk()
            ->assertSee('data-notification-center-counts-url="'.e(route('notifications.center.counts')).'"', false);
    }

    public function test_a_read_row_is_marked_as_such_for_the_skin(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $this->seedDiaryComment($viewer, $actor, readAt: now());

        $this->actingAs($viewer)->get(route('notifications.center'))
            ->assertOk()
            ->assertSee('class="push isread nclink"', false);
    }

    /** The one row OpenPNE 3 did not let you click through: it asks for the decision in place. */
    public function test_a_pending_friend_row_offers_the_decision_instead_of_a_link(): void
    {
        [$viewer, $requester] = Member::factory()->count(2)->create()->all();
        $row = $this->seedFriendRequest($viewer, $requester);

        $response = $this->actingAs($viewer)->get(route('notifications.center'))->assertOk();

        $response->assertSee('class="push"', false)
            ->assertDontSee('nclink', false)
            ->assertSee('class="push_yesno"', false)
            ->assertSee('data-accept-url="'.e(route('notifications.center.friendAccept', $row)).'"', false)
            ->assertSee('data-reject-url="'.e(route('notifications.center.friendReject', $row)).'"', false)
            ->assertSee(__('Do you accept %friend% link request?'));
    }

    public function test_marking_everything_read_leaves_a_pending_decision_standing(): void
    {
        [$viewer, $requester] = Member::factory()->count(2)->create()->all();
        $this->seedFriendRequest($viewer, $requester);

        $this->actingAs($viewer)->post(route('notifications.readAll'));
        $this->freshRequestState();

        $this->actingAs($viewer)->get(route('notifications.center'))
            ->assertOk()
            ->assertSee('class="push_yesno"', false);
    }

    public function test_a_friend_row_whose_request_is_gone_no_longer_asks(): void
    {
        [$viewer, $requester] = Member::factory()->count(2)->create()->all();
        $this->seedFriendRequest($viewer, $requester);
        DB::table('friend_requests')->delete();

        $this->actingAs($viewer)->get(route('notifications.center'))
            ->assertOk()
            ->assertDontSee('class="push_yesno"', false);
    }

    public function test_the_panel_keeps_openpne3s_cap_and_stays_out_of_shared_caches(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        foreach (range(1, NotificationCenterWindow::LIMIT + 5) as $ignored) {
            $this->seedDiaryComment($viewer, $actor);
        }

        $response = $this->actingAs($viewer)->get(route('notifications.center'))->assertOk();

        $this->assertSame(NotificationCenterWindow::LIMIT, substr_count((string) $response->getContent(), 'data-notify-id='));
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
    }

    public function test_a_guest_cannot_read_the_panel(): void
    {
        $this->get(route('notifications.center'))->assertRedirect(route('login'));
    }

    /** A page of rows must cost what a handful does — the panel resolves nothing per row. */
    public function test_a_full_panel_costs_what_a_near_empty_one_does(): void
    {
        $few = $this->memberWithRows(2);
        $many = $this->memberWithRows(NotificationCenterWindow::LIMIT);

        $this->assertSame($this->queryCountFor($few), $this->queryCountFor($many));
    }

    public function test_accepting_answers_the_request_the_row_names_and_retires_the_row(): void
    {
        [$viewer, $requester] = Member::factory()->count(2)->create()->all();
        $row = $this->seedFriendRequest($viewer, $requester);

        $this->actingAs($viewer)
            ->postJson(route('notifications.center.friendAccept', $row))
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => __('%Friend% request accepted.')]);

        $this->assertDatabaseMissing('friend_requests', ['requester_id' => $requester->getKey(), 'target_id' => $viewer->getKey()]);
        $this->assertNotNull($viewer->notifications()->whereKey($row)->sole()->read_at);
    }

    public function test_rejecting_answers_the_request_the_row_names(): void
    {
        [$viewer, $requester] = Member::factory()->count(2)->create()->all();
        $row = $this->seedFriendRequest($viewer, $requester);

        $this->actingAs($viewer)
            ->postJson(route('notifications.center.friendReject', $row))
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => __('%Friend% request rejected.')]);

        $this->assertDatabaseMissing('friend_requests', ['requester_id' => $requester->getKey(), 'target_id' => $viewer->getKey()]);
    }

    public function test_answering_twice_reports_the_request_as_already_settled(): void
    {
        [$viewer, $requester] = Member::factory()->count(2)->create()->all();
        $row = $this->seedFriendRequest($viewer, $requester);

        $this->actingAs($viewer)->postJson(route('notifications.center.friendAccept', $row))->assertOk();
        $this->freshRequestState();

        $this->actingAs($viewer)
            ->postJson(route('notifications.center.friendAccept', $row))
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => __('This request has already been answered.')]);
    }

    public function test_a_member_cannot_answer_through_someone_elses_row(): void
    {
        [$viewer, $other, $requester] = Member::factory()->count(3)->create()->all();
        $row = $this->seedFriendRequest($other, $requester);

        $this->actingAs($viewer)->postJson(route('notifications.center.friendAccept', $row))->assertNotFound();

        $this->assertDatabaseHas('friend_requests', ['requester_id' => $requester->getKey(), 'target_id' => $other->getKey()]);
    }

    public function test_a_row_that_is_not_a_friend_request_cannot_be_answered(): void
    {
        [$viewer, $actor] = Member::factory()->count(2)->create()->all();
        $row = $this->seedDiaryComment($viewer, $actor);

        $this->actingAs($viewer)->postJson(route('notifications.center.friendAccept', $row))->assertNotFound();
    }

    /**
     * Asserted on the routes because the framework skips its own forgery check while running tests, so a
     * request cannot show it.
     */
    public function test_the_decisions_sit_behind_the_web_groups_forgery_and_session_checks(): void
    {
        foreach (['notifications.center', 'notifications.center.counts', 'notifications.center.friendAccept', 'notifications.center.friendReject'] as $name) {
            $middleware = app('router')->getRoutes()->getByName($name)->gatherMiddleware();

            $this->assertContains('web', $middleware, "route [{$name}] left the web group");
            $this->assertContains('auth', $middleware, "route [{$name}] is reachable by a guest");
            $this->assertContains('auth.session', $middleware, "route [{$name}] accepts a stale session");
        }
    }

    private function memberWithRows(int $count): Member
    {
        $viewer = Member::factory()->create();
        foreach (Member::factory()->count($count)->create() as $actor) {
            $this->seedDiaryComment($viewer, $actor);
        }

        return $viewer;
    }

    private function queryCountFor(Member $viewer): int
    {
        $this->actingAs($viewer)->get(route('notifications.center'))->assertOk(); // warm process-wide caches
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($viewer)->get(route('notifications.center'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function seedFriendRequest(Member $viewer, Member $requester): string
    {
        DB::table('friend_requests')->insertOrIgnore(['requester_id' => $requester->getKey(), 'target_id' => $viewer->getKey()]);

        return $this->seedRow($viewer, FriendRequestedNotification::class, [
            'kind' => 'friend_requested',
            'requester_id' => $requester->getKey(),
        ]);
    }

    private function seedDiaryComment(Member $viewer, Member $actor, ?\DateTimeInterface $readAt = null): string
    {
        return $this->seedRow($viewer, DiaryCommentedNotification::class, [
            'kind' => 'diary_commented',
            'commenter_id' => $actor->getKey(),
        ], $readAt);
    }

    /** @param array<string, mixed> $data */
    private function seedRow(Member $member, string $type, array $data, ?\DateTimeInterface $readAt = null): string
    {
        $id = (string) Str::uuid();
        $member->notifications()->create(['id' => $id, 'type' => $type, 'data' => $data, 'read_at' => $readAt]);

        return $id;
    }
}

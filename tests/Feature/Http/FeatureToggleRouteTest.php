<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Notifications\Friend\FriendRequestedNotification;
use App\Support\Feature;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What a switched-off feature unit answers over HTTP: 404 on everything it owns, for a member and
 * for a guest alike, while every other unit keeps working. The routes stay registered — the gate is
 * App\Http\Middleware\EnsureFeatureEnabled.
 */
class FeatureToggleRouteTest extends TestCase
{
    use RefreshDatabase;

    private Member $member;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = Member::factory()->create();
        $this->community = Group::factory()->create();
        GroupMember::factory()->admin()->create([
            'group_id' => $this->community->getKey(),
            'member_id' => $this->member->getKey(),
        ]);
    }

    public function test_each_unit_answers_404_on_its_own_routes_while_it_is_off(): void
    {
        foreach ($this->representativeRoutes() as $key => [$get, $post]) {
            $feature = Feature::from($key);

            $this->actingAs($this->member)->get($get)->assertOk();
            $this->assertNotSame(404, $this->actingAs($this->member)->post($post)->status(),
                "{$key}: the representative POST already 404s while the unit is on, so it proves nothing");

            $this->setSnsSetting($feature->settingKey(), false);

            $this->actingAs($this->member)->get($get)->assertNotFound();
            $this->actingAs($this->member)->post($post)->assertNotFound();

            $this->setSnsSetting($feature->settingKey(), true);
        }
    }

    /**
     * The board and the calendar live inside groups, so switching groups off closes all
     * three — their own rows say enabled and are overruled.
     */
    public function test_switching_communities_off_closes_the_board_and_the_calendar_too(): void
    {
        $this->setSnsSetting(Feature::GroupTopic->settingKey(), true);
        $this->setSnsSetting(Feature::GroupEvent->settingKey(), true);
        $this->setSnsSetting(Feature::Group->settingKey(), false);

        $this->actingAs($this->member)->get('/groups')->assertNotFound();
        $this->actingAs($this->member)->get('/groups/recent')->assertNotFound();
        $this->actingAs($this->member)->get("/groups/{$this->community->getKey()}/topics")->assertNotFound();
        $this->actingAs($this->member)->get("/groups/{$this->community->getKey()}/events")->assertNotFound();
        // Unrelated units are untouched.
        $this->actingAs($this->member)->get('/diary/list')->assertOk();
    }

    public function test_switching_the_board_off_leaves_communities_and_the_calendar_open(): void
    {
        $this->setSnsSetting(Feature::GroupTopic->settingKey(), false);

        $this->actingAs($this->member)->get("/groups/{$this->community->getKey()}/topics")->assertNotFound();
        $this->actingAs($this->member)->get('/groups')->assertOk();
        $this->actingAs($this->member)->get("/groups/{$this->community->getKey()}/events")->assertOk();
    }

    public function test_a_guest_meets_a_404_not_the_login_bounce_when_diaries_are_off(): void
    {
        // Web-public diaries are on, so the guest read screens render for a guest today.
        $this->setSnsSetting(SnsSettingKey::DiaryAllowWebPublic, true);
        $this->get('/diary/list')->assertOk();

        $this->setSnsSetting(Feature::Diary->settingKey(), false);

        // 404, not the login redirect: signing in would not open the screen either.
        $this->get('/diary/list')->assertNotFound();
    }

    public function test_a_guest_still_meets_the_login_bounce_when_only_web_public_diaries_are_off(): void
    {
        $this->setSnsSetting(SnsSettingKey::DiaryAllowWebPublic, false);

        $this->get('/diary/list')->assertRedirect(route('login'));
    }

    public function test_a_guest_meets_a_404_on_a_missing_member_archive_when_diaries_are_off(): void
    {
        $this->setSnsSetting(SnsSettingKey::DiaryAllowWebPublic, true);

        // On: the binding's missing() handler hides whether the id exists behind the login bounce.
        $this->get('/diary/listMember/999999')->assertRedirect(route('login'));

        $this->setSnsSetting(Feature::Diary->settingKey(), false);

        // Off: the gate outranks SubstituteBindings (bootstrap/app.php priority list), so the
        // missing() handler never runs and the guest meets the same 404 as everyone else.
        $this->get('/diary/listMember/999999')->assertNotFound();
    }

    public function test_the_diary_section_of_member_config_closes_with_the_diary(): void
    {
        $this->assertNotSame(404, $this->actingAs($this->member)->post('/member/config/diary')->status());

        $this->setSnsSetting(Feature::Diary->settingKey(), false);

        $this->actingAs($this->member)->post('/member/config/diary')->assertNotFound();
    }

    /**
     * The friend diary feed is a lens the friend unit owns inside the diary module. The friendships
     * it filters by survive the toggle, so the deep link would otherwise keep serving the lens.
     */
    public function test_the_friend_diary_feed_closes_with_friends_while_the_diary_stays_open(): void
    {
        $friend = Member::factory()->create();
        DB::table('friendships')->insert([
            ['member_id' => $this->member->getKey(), 'friend_id' => $friend->getKey()],
            ['member_id' => $friend->getKey(), 'friend_id' => $this->member->getKey()],
        ]);
        Diary::factory()->friends()->create(['member_id' => $friend->getKey()]);

        $this->actingAs($this->member)->get('/diary/listFriend')->assertOk();

        $this->setSnsSetting(Feature::Friend->settingKey(), false);

        $this->actingAs($this->member)->get('/diary/listFriend')->assertNotFound();
        // The module it lives in is untouched.
        $this->actingAs($this->member)->get('/diary/list')->assertOk();
        $this->actingAs($this->member)->get('/diary/new')->assertOk();
    }

    public function test_the_notification_centre_friend_decisions_close_with_friends(): void
    {
        $requester = Member::factory()->create();
        $row = $this->seedFriendRequest($this->member, $requester);

        $this->actingAs($this->member)->postJson(route('notifications.center.friendAccept', $row))->assertOk();

        $this->setSnsSetting(Feature::Friend->settingKey(), false);
        $this->freshRequestState();

        $this->actingAs($this->member)->postJson(route('notifications.center.friendAccept', $row))->assertNotFound();
        $this->actingAs($this->member)->postJson(route('notifications.center.friendReject', $row))->assertNotFound();
        // The panel itself is not friend-owned, so it stays open.
        $this->actingAs($this->member)->get(route('notifications.center'))->assertOk();
    }

    /** @return array<string, array{string, string}> feature value => [representative GET, representative POST] */
    private function representativeRoutes(): array
    {
        $group = $this->community->getKey();

        return [
            'diary' => ['/diary/list', '/diary/create'],
            'directMessage' => ['/message/receiveList', '/message/sendToFriend'],
            'timeline' => ['/timeline', '/timeline/create'],
            'group' => ['/groups', '/groups/edit'],
            'groupTopic' => ["/groups/{$group}/topics", "/groups/{$group}/topics"],
            'groupEvent' => ["/groups/{$group}/events", "/groups/{$group}/events"],
            'friend' => ['/friend/list', '/friend/link'],
        ];
    }

    private function seedFriendRequest(Member $target, Member $requester): string
    {
        DB::table('friend_requests')->insertOrIgnore(['requester_id' => $requester->getKey(), 'target_id' => $target->getKey()]);

        $id = (string) Str::uuid();
        $target->notifications()->create([
            'id' => $id,
            'type' => FriendRequestedNotification::class,
            'data' => ['kind' => 'friend_requested', 'requester_id' => $requester->getKey()],
        ]);

        return $id;
    }
}

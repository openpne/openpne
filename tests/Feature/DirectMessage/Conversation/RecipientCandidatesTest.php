<?php

namespace Tests\Feature\DirectMessage\Conversation;

use App\Features\DirectMessage\DirectMessageAccess;
use App\Features\DirectMessage\Queries\RecipientCandidates;
use App\Models\Member;
use App\Models\MemberImage;
use App\Support\AvatarColor;
use App\Support\Feature;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * Who the new-message picker offers, and the invariant that holds it to
 * DirectMessageAccess::canSend — a name offered here must reach a conversation with a composer in it.
 */
class RecipientCandidatesTest extends ConversationTestCase
{
    private const URI = '/messages/recipients';

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get(self::URI)->assertRedirect('/login');
    }

    public function test_the_unit_switched_off_takes_the_picker_and_its_search(): void
    {
        $viewer = Member::factory()->create();
        Member::factory()->create(['name' => 'Match Anyone']);

        $this->setSnsSetting(Feature::DirectMessage->settingKey(), false);

        $this->actingAs($viewer)->get('/messages/new')->assertNotFound();
        $this->actingAs($viewer)->getJson(self::URI.'?q=Match')->assertNotFound();
    }

    /** The literal is declared before {member}, so it is never read as a member id. */
    public function test_the_picker_is_a_screen_of_its_own(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get('/messages/new')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('message/new'));
    }

    /** At rest the question is "who do I write to", and the answer is the people you know. */
    public function test_a_blank_term_stops_at_the_friends(): void
    {
        $viewer = Member::factory()->create();
        $zen = Member::factory()->create(['name' => 'Zen']);
        $yui = Member::factory()->create(['name' => 'Yui']);
        Member::factory()->create(['name' => 'Abe']);
        $this->makeFriends($viewer, $zen);
        $this->makeFriends($viewer, $yui);

        $this->assertSame([$yui->getKey(), $zen->getKey()], $this->candidateIds($viewer, ''));
    }

    public function test_a_term_reaches_the_rest_of_the_site_behind_the_friends(): void
    {
        $viewer = Member::factory()->create();
        // The friend sorts last by name, so friends-first is what puts them at the top.
        $friend = Member::factory()->create(['name' => 'Match Zeta']);
        $stranger = Member::factory()->create(['name' => 'Match Alpha']);
        $this->makeFriends($viewer, $friend);

        $this->assertSame([$friend->getKey(), $stranger->getKey()], $this->candidateIds($viewer, 'Match'));
        // The term is a match anywhere in the name, not only at its start.
        $this->assertSame([$friend->getKey(), $stranger->getKey()], $this->candidateIds($viewer, 'atch'));
    }

    public function test_a_friend_is_not_repeated_by_the_second_query(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create(['name' => 'Match Friend']);
        $stranger = Member::factory()->create(['name' => 'Match Stranger']);
        $this->makeFriends($viewer, $friend);

        $this->assertSame([$friend->getKey(), $stranger->getKey()], $this->candidateIds($viewer, 'Match'));
    }

    public function test_it_never_offers_the_viewer_themselves(): void
    {
        $viewer = Member::factory()->create(['name' => 'Match Self']);
        $other = Member::factory()->create(['name' => 'Match Other']);

        $this->assertSame([$other->getKey()], $this->candidateIds($viewer, 'Match'));
    }

    public function test_it_never_offers_a_banned_member(): void
    {
        $viewer = Member::factory()->create();
        Member::factory()->create(['name' => 'Match Banned'])->forceFill(['is_login_rejected' => true])->save();
        $friend = Member::factory()->create(['name' => 'Match Friend']);
        $friend->forceFill(['is_login_rejected' => true])->save();
        $this->makeFriends($viewer, $friend);

        $this->assertSame([], $this->candidateIds($viewer, 'Match'));
    }

    public function test_it_never_offers_either_side_of_a_block(): void
    {
        $viewer = Member::factory()->create();
        $blocked = Member::factory()->create(['name' => 'Match Blocked']);
        $blocker = Member::factory()->create(['name' => 'Match Blocker']);
        $friend = Member::factory()->create(['name' => 'Match Friend']);
        $this->makeFriends($viewer, $friend);
        $this->block($viewer, $blocked);
        $this->block($blocker, $viewer);
        $this->block($friend, $viewer);

        $this->assertSame([], $this->candidateIds($viewer, 'Match'));
    }

    public function test_it_offers_at_most_a_screenful_with_the_friends_at_the_top(): void
    {
        $viewer = Member::factory()->create();
        // Names put every stranger before every friend alphabetically, so a lost friend tier shows up
        // as a reordering, not only as a shorter list.
        $friends = [];
        foreach (range(1, 5) as $i) {
            $friend = Member::factory()->create(['name' => "Match Friend {$i}"]);
            $this->makeFriends($viewer, $friend);
            $friends[] = $friend->getKey();
        }
        Member::factory()->count(20)->sequence(fn ($sequence) => ['name' => 'Match Anon '.$sequence->index])->create();

        $ids = $this->candidateIds($viewer, 'Match');

        $this->assertCount(RecipientCandidates::LIMIT, $ids);
        $this->assertSame($friends, array_slice($ids, 0, 5));
    }

    /** A recipient is a member id, so nothing about the name has to fit inside a message. */
    public function test_a_long_name_is_still_offered(): void
    {
        $viewer = Member::factory()->create();
        $long = Member::factory()->create(['name' => str_repeat('あ', 140)]);

        $this->assertSame([$long->getKey()], $this->candidateIds($viewer, 'あ'));
    }

    public function test_a_wildcard_in_the_term_matches_it_literally(): void
    {
        $viewer = Member::factory()->create();
        $percent = Member::factory()->create(['name' => 'Full 100%']);
        $underscore = Member::factory()->create(['name' => 'Snake_case']);
        Member::factory()->create(['name' => 'Snakexcase']);

        $this->assertSame([$percent->getKey()], $this->candidateIds($viewer, '%'));
        $this->assertSame([$underscore->getKey()], $this->candidateIds($viewer, 'Snake_'));
    }

    public function test_switching_friends_off_drops_the_friend_tier(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create(['name' => 'Match Zeta']);
        $stranger = Member::factory()->create(['name' => 'Match Alpha']);
        $this->makeFriends($viewer, $friend);

        $this->setSnsSetting(Feature::Friend->settingKey(), false);

        // Both stay offerable — only the ranking the friend graph gave goes.
        $this->assertSame([$stranger->getKey(), $friend->getKey()], $this->candidateIds($viewer, 'Match'));
    }

    public function test_a_term_over_the_length_cap_is_rejected(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->getJson(self::URI.'?q='.str_repeat('a', 101))
            ->assertStatus(422)->assertJsonValidationErrors('q');
    }

    public function test_it_throttles_after_the_per_member_cap(): void
    {
        // The picker's search shares the mention pickers' keystroke limiter. Lower the per-member
        // limb; keep the per-IP one loose so the member cap is what trips.
        config(['openpne.throttle.mention_search' => 2, 'openpne.throttle.mention_search_ip' => 1000]);
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->getJson(self::URI)->assertOk();
        $this->actingAs($viewer)->getJson(self::URI)->assertOk();
        $this->actingAs($viewer)->getJson(self::URI)->assertStatus(429);
    }

    public function test_a_candidate_carries_the_member_ref_shape(): void
    {
        $viewer = Member::factory()->create();
        $candidate = Member::factory()->create(['name' => 'Match Ann']);
        $candidate->forceFill(['avatar_color' => AvatarColor::Green])->save();
        MemberImage::factory()->create(['member_id' => $candidate->getKey()]);
        $expected = $candidate->load('avatar.file')->avatar->file->thumbnailUrl(120, 120, square: true);

        $this->actingAs($viewer)->getJson(self::URI.'?q=Match')
            ->assertOk()
            ->assertExactJson(['candidates' => [[
                'id' => $candidate->getKey(),
                'name' => 'Match Ann',
                'imageUrl' => $expected,
                'avatarColor' => '#15803d',
            ]]]);
    }

    public function test_the_avatars_do_not_cost_a_query_per_candidate(): void
    {
        $viewer = Member::factory()->create();
        foreach (range(1, 4) as $i) {
            $friend = Member::factory()->create(['name' => "Match Friend {$i}"]);
            MemberImage::factory()->create(['member_id' => $friend->getKey()]);
            $this->makeFriends($viewer, $friend);
        }
        foreach (range(1, 4) as $i) {
            MemberImage::factory()->create(['member_id' => Member::factory()->create(['name' => "Match Anon {$i}"])->getKey()]);
        }

        DB::enableQueryLog();
        $this->actingAs($viewer)->getJson(self::URI.'?q=Match')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Each tier resolves its avatars (and their files) in one query, so eight candidates cost
        // what one does.
        $this->assertLessThan(14, $queries, "the endpoint ran {$queries} queries — the avatars are likely resolving per candidate");
    }

    /**
     * The first gate stated against the second: every name the picker offers must be one the send
     * would accept, or the picker sends members to a conversation with no composer in it.
     */
    public function test_every_offered_candidate_may_be_written_to(): void
    {
        $viewer = Member::factory()->create();
        Member::factory()->create(['name' => 'Match Alice']);
        $friend = Member::factory()->create(['name' => 'Match Bob']);
        $this->makeFriends($viewer, $friend);
        // Names the picker must not offer, each for a different reason.
        Member::factory()->create(['name' => 'Match Banned'])->forceFill(['is_login_rejected' => true])->save();
        $blocked = Member::factory()->create(['name' => 'Match Blocked']);
        $this->block($blocked, $viewer);

        $ids = $this->candidateIds($viewer, 'Match');
        $this->assertNotEmpty($ids, 'nothing offered — the assertion below would be vacuous');

        foreach ($ids as $id) {
            $candidate = Member::findOrFail($id);
            $this->assertTrue(
                DirectMessageAccess::canSend($viewer, $candidate),
                "candidate {$candidate->name} was offered but the send would refuse them",
            );
        }
    }

    /** @return list<int> */
    private function candidateIds(Member $viewer, string $q): array
    {
        $response = $this->actingAs($viewer)->getJson(self::URI.'?q='.rawurlencode($q))->assertOk();

        return array_column($response->json('candidates'), 'id');
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }

    private function block(Member $blocker, Member $blocked): void
    {
        DB::table('member_blocks')->insert([
            'blocker_id' => $blocker->getKey(),
            'blocked_id' => $blocked->getKey(),
        ]);
    }
}

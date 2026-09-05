<?php

namespace Tests\Feature\Timeline;

use App\Models\Member;
use App\Models\MemberImage;
use App\Support\AvatarColor;
use App\Support\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Never someone the submit would drop — the storage half holds the drop side of that agreement. */
class MentionCandidatesTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/timeline/mention-candidates';

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(self::URI)->assertRedirect('/login');
    }

    public function test_a_friend_comes_before_a_stranger_matching_the_same_term(): void
    {
        $viewer = Member::factory()->create();
        // The friend sorts last by name, so friends-first is what puts them at the top.
        $friend = Member::factory()->create(['name' => 'Match Zeta']);
        $stranger = Member::factory()->create(['name' => 'Match Alpha']);
        $this->makeFriends($viewer, $friend);

        $this->assertSame([$friend->getKey(), $stranger->getKey()], $this->candidateIds($viewer, 'Match'));
    }

    public function test_each_tier_is_ordered_by_name_then_id(): void
    {
        $viewer = Member::factory()->create();
        $bea = Member::factory()->create(['name' => 'Match Bea']);
        [$first, $second] = Member::factory()->count(2)->create(['name' => 'Match Ann'])->all();

        $this->assertSame(
            [$first->getKey(), $second->getKey(), $bea->getKey()],
            $this->candidateIds($viewer, 'Match'),
        );
    }

    public function test_it_never_offers_the_viewer_themselves(): void
    {
        $viewer = Member::factory()->create(['name' => 'Match Self']);
        $other = Member::factory()->create(['name' => 'Match Other']);

        $this->assertSame([$other->getKey()], $this->candidateIds($viewer, 'Match'));
    }

    public function test_it_never_offers_a_name_too_long_to_ever_mention(): void
    {
        // 139 is the last name that fits "@" plus the body's 140 code points, and 139 × "あ" is 417
        // bytes — the pair is what catches a byte-counting length function.
        $viewer = Member::factory()->create();
        $fits = Member::factory()->create(['name' => str_repeat('a', 139)]);
        Member::factory()->create(['name' => str_repeat('a', 140)]);
        $fitsWide = Member::factory()->create(['name' => str_repeat('あ', 139)]);
        Member::factory()->create(['name' => str_repeat('あ', 140)]);

        $this->assertSame([$fits->getKey(), $fitsWide->getKey()], $this->candidateIds($viewer, ''));
    }

    public function test_it_never_offers_a_banned_member(): void
    {
        $viewer = Member::factory()->create();
        $banned = Member::factory()->create(['name' => 'Match Banned']);
        $banned->forceFill(['is_login_rejected' => true])->save();
        $friendBanned = Member::factory()->create(['name' => 'Match Friend']);
        $friendBanned->forceFill(['is_login_rejected' => true])->save();
        $this->makeFriends($viewer, $friendBanned);

        $this->assertSame([], $this->candidateIds($viewer, 'Match'));
    }

    public function test_it_never_offers_either_side_of_a_block(): void
    {
        $viewer = Member::factory()->create();
        $blocked = Member::factory()->create(['name' => 'Match Blocked']);
        $blocker = Member::factory()->create(['name' => 'Match Blocker']);
        $this->block($viewer, $blocked);
        $this->block($blocker, $viewer);

        $this->assertSame([], $this->candidateIds($viewer, 'Match'));
    }

    public function test_a_block_hides_a_friend_too(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create(['name' => 'Match Friend']);
        $this->makeFriends($viewer, $friend);
        $this->block($friend, $viewer);

        $this->assertSame([], $this->candidateIds($viewer, 'Match'));
    }

    public function test_it_returns_at_most_eight_with_the_friends_at_the_top(): void
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
        Member::factory()->count(10)->sequence(fn ($sequence) => ['name' => 'Match Anon '.$sequence->index])->create();

        $ids = $this->candidateIds($viewer, 'Match');

        $this->assertCount(8, $ids);
        $this->assertSame($friends, array_slice($ids, 0, 5));
    }

    public function test_an_empty_term_lists_the_friends_first(): void
    {
        $viewer = Member::factory()->create(['name' => 'Viewer']);
        $yui = Member::factory()->create(['name' => 'Yui']);
        $zen = Member::factory()->create(['name' => 'Zen']);
        $abe = Member::factory()->create(['name' => 'Abe']);
        $this->makeFriends($viewer, $yui);
        $this->makeFriends($viewer, $zen);

        $this->assertSame([$yui->getKey(), $zen->getKey(), $abe->getKey()], $this->candidateIds($viewer, ''));
    }

    public function test_a_friend_is_not_repeated_by_the_second_query(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create(['name' => 'Match Friend']);
        $stranger = Member::factory()->create(['name' => 'Match Stranger']);
        $this->makeFriends($viewer, $friend);

        $this->assertSame([$friend->getKey(), $stranger->getKey()], $this->candidateIds($viewer, 'Match'));
    }

    public function test_a_wildcard_in_the_term_matches_it_literally(): void
    {
        $viewer = Member::factory()->create();
        $percent = Member::factory()->create(['name' => 'Full 100%']);
        $underscore = Member::factory()->create(['name' => 'Snake_case']);
        Member::factory()->create(['name' => 'Snakexcase']);

        // Unescaped, `%` would match the whole SNS and `_` any single character.
        $this->assertSame([$percent->getKey()], $this->candidateIds($viewer, '%'));
        $this->assertSame([$percent->getKey()], $this->candidateIds($viewer, '100%'));
        $this->assertSame([$underscore->getKey()], $this->candidateIds($viewer, 'Snake_'));
    }

    public function test_a_term_over_the_length_cap_is_rejected(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->getJson(self::URI.'?q='.str_repeat('a', 101))
            ->assertStatus(422)->assertJsonValidationErrors('q');
    }

    public function test_it_throttles_after_the_per_member_cap(): void
    {
        // Lower the per-member limit; keep the per-IP limb loose so the member cap is what trips.
        config(['openpne.throttle.mention_search' => 2, 'openpne.throttle.mention_search_ip' => 1000]);
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->getJson(self::URI)->assertOk();
        $this->actingAs($viewer)->getJson(self::URI)->assertOk();
        $this->actingAs($viewer)->getJson(self::URI)->assertStatus(429);
    }

    public function test_switching_the_timeline_off_stops_serving_candidates(): void
    {
        $viewer = Member::factory()->create();
        Member::factory()->create(['name' => 'Match Anyone']);

        $this->setSnsSetting(Feature::Timeline->settingKey(), false);

        $this->actingAs($viewer)->getJson(self::URI.'?q=Match')->assertNotFound();
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
                'isAi' => false,
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

        // Each tier resolves its avatars in one query, so the bound sits well under the
        // eight-candidate steady state plus a per-row read.
        $this->assertLessThan(14, $queries, "the endpoint ran {$queries} queries — the avatars are likely resolving per candidate");
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

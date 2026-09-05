<?php

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\Queries\GroupTalkMentionCandidates;
use App\Features\Timeline\Actions\ResolveMentions;
use App\Models\Group;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class TalkMentionCandidatesTest extends TalkTestCase
{
    private function joined(Group $group, string $name): Member
    {
        $member = Member::factory()->create(['name' => $name]);
        DB::table('group_members')->insert([
            'group_id' => $group->getKey(), 'member_id' => $member->getKey(), 'role' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $member;
    }

    private function url(Group $group, string $q = ''): string
    {
        return "/groups/{$group->getKey()}/talk/mention-candidates?q=".urlencode($q);
    }

    public function test_the_room_is_the_candidate_set(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $inside = $this->joined($group, 'Insider');
        Member::factory()->create(['name' => 'Outsider']);

        $names = array_column(
            $this->actingAs($viewer)->getJson($this->url($group))->assertOk()->json('candidates'),
            'name',
        );

        $this->assertSame(['Insider'], $names);
        $this->assertNotContains('Outsider', $names);
        $this->assertNotContains($viewer->name, $names, 'never the viewer');
        $this->assertIsInt($inside->getKey());
    }

    public function test_candidates_carry_the_member_ref_shape(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $this->joined($group, 'Insider');

        $this->actingAs($viewer)->getJson($this->url($group))
            ->assertJsonStructure(['candidates' => [['id', 'name', 'imageUrl', 'avatarColor']]]);
    }

    public function test_a_banned_member_is_not_offered(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $this->joined($group, 'Banned')->forceFill(['is_login_rejected' => true])->save();

        $this->actingAs($viewer)->getJson($this->url($group))->assertJsonCount(0, 'candidates');
    }

    /** @return array<string, array{0: bool}> */
    public static function blockDirections(): array
    {
        return ['the viewer blocked them' => [true], 'they blocked the viewer' => [false]];
    }

    #[DataProvider('blockDirections')]
    public function test_neither_side_of_a_block_is_offered(bool $viewerBlocks): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $other = $this->joined($group, 'Blocked');
        DB::table('member_blocks')->insert($viewerBlocks
            ? ['blocker_id' => $viewer->getKey(), 'blocked_id' => $other->getKey()]
            : ['blocker_id' => $other->getKey(), 'blocked_id' => $viewer->getKey()]);

        $this->actingAs($viewer)->getJson($this->url($group))->assertJsonCount(0, 'candidates');
    }

    public function test_the_term_matches_a_wildcard_literally(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $this->joined($group, '100% cotton');
        $this->joined($group, 'Someone else');

        $names = array_column(
            $this->actingAs($viewer)->getJson($this->url($group, '%'))->json('candidates'),
            'name',
        );

        $this->assertSame(['100% cotton'], $names);
    }

    public function test_at_most_eight_are_offered_ordered_by_name_then_id(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        foreach (range(1, 10) as $i) {
            $this->joined($group, 'Member '.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $names = array_column(
            $this->actingAs($viewer)->getJson($this->url($group))->json('candidates'),
            'name',
        );

        $this->assertCount(GroupTalkMentionCandidates::LIMIT, $names);
        $this->assertSame($names, array_values(array_unique($names)));
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
    }

    public function test_a_non_member_is_refused_the_roster(): void
    {
        $group = $this->group();
        $this->joined($group, 'Insider');

        $this->actingAs(Member::factory()->create())->getJson($this->url($group))->assertNotFound();
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $group = $this->group();

        $this->get($this->url($group))->assertRedirect('/login');
    }

    public function test_every_offered_candidate_resolves(): void
    {
        $group = $this->group();
        $viewer = $this->memberOf($group);
        $this->joined($group, 'Alice');
        $this->joined($group, 'Bob');
        // Names the picker must not offer, each for a different reason.
        $this->joined($group, 'Banned')->forceFill(['is_login_rejected' => true])->save();
        $blocked = $this->joined($group, 'Blocked');
        DB::table('member_blocks')->insert(['blocker_id' => $blocked->getKey(), 'blocked_id' => $viewer->getKey()]);
        Member::factory()->create(['name' => 'Outsider']);

        $candidates = $this->actingAs($viewer)->getJson($this->url($group))->json('candidates');
        $this->assertNotEmpty($candidates, 'nothing offered — the assertion below would be vacuous');

        foreach ($candidates as $candidate) {
            $body = 'hi @'.$candidate['name'];
            $resolved = app(ResolveMentions::class)($viewer, $body, [
                ['member_id' => $candidate['id'], 'offset' => 3, 'length' => mb_strlen($candidate['name']) + 1],
            ], $group);

            $this->assertCount(1, $resolved, "candidate {$candidate['name']} was offered but the submit would drop it");
        }
    }
}

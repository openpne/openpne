<?php

declare(strict_types=1);

namespace Tests\Feature\GroupTalk;

use App\Features\GroupTalk\Queries\MutedTalkRooms;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Support\SnsSettingKey;

/** The muted-room list the notification settings page shows as the exceptions to its toggles. */
class MutedTalkRoomsTest extends TalkTestCase
{
    private function mutedRooms(): MutedTalkRooms
    {
        return app(MutedTalkRooms::class);
    }

    private function joins(Member $member, Group $group, bool $muted): void
    {
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'is_talk_muted' => $muted,
        ]);
    }

    public function test_only_the_muted_rooms_are_listed_in_name_order(): void
    {
        $member = Member::factory()->create();
        $quiet = Group::factory()->create(['name' => 'Zither']);
        $alsoQuiet = Group::factory()->create(['name' => 'Anvil']);
        $audible = Group::factory()->create(['name' => 'Bellows']);

        $this->joins($member, $quiet, muted: true);
        $this->joins($member, $alsoQuiet, muted: true);
        $this->joins($member, $audible, muted: false);

        $this->assertSame([
            ['id' => $alsoQuiet->getKey(), 'name' => 'Anvil'],
            ['id' => $quiet->getKey(), 'name' => 'Zither'],
        ], ($this->mutedRooms())($member));
    }

    public function test_another_members_mute_is_not_listed(): void
    {
        $member = Member::factory()->create();
        $other = Member::factory()->create();
        $group = Group::factory()->create(['name' => 'Anvil']);

        $this->joins($other, $group, muted: true);
        $this->joins($member, $group, muted: false);

        $this->assertSame([], ($this->mutedRooms())($member));
    }

    /** The flag survives the switch, but a room with no talk screen to open is not an exception to offer. */
    public function test_the_list_is_empty_while_the_unit_is_off(): void
    {
        $member = Member::factory()->create();
        $this->joins($member, Group::factory()->create(['name' => 'Anvil']), muted: true);

        $this->setSnsSetting(SnsSettingKey::FeatureGroupTalkEnabled, false);

        $this->assertSame([], ($this->mutedRooms())($member));
    }
}

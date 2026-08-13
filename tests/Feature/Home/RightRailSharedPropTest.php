<?php

namespace Tests\Feature\Home;

use App\Models\File;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use App\Models\MemberImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RightRailSharedPropTest extends TestCase
{
    use RefreshDatabase;

    public function test_rail_carries_the_members_friends_and_communities(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        DB::table('friendships')->insert([
            ['member_id' => $viewer->getKey(), 'friend_id' => $friend->getKey()],
            ['member_id' => $friend->getKey(), 'friend_id' => $viewer->getKey()],
        ]);
        $group = Group::factory()->create();
        GroupMember::factory()->member()->create(['group_id' => $group->getKey(), 'member_id' => $viewer->getKey()]);

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('rightRail.people.kind', 'friends')
                ->has('rightRail.people.items', 1)
                ->where('rightRail.people.items.0.id', $friend->getKey())
                ->where('rightRail.people.items.0.href', "/member/{$friend->getKey()}")
                ->has('rightRail.joinedGroups', 1)
                ->where('rightRail.joinedGroups.0.href', "/groups/{$group->getKey()}")
            );
    }

    public function test_rail_images_are_sized_for_its_own_tiles_not_the_smaller_avatars_elsewhere(): void
    {
        $viewer = Member::factory()->create();
        $friend = Member::factory()->create();
        MemberImage::factory()->create(['member_id' => $friend->getKey()]);
        DB::table('friendships')->insert([
            ['member_id' => $viewer->getKey(), 'friend_id' => $friend->getKey()],
            ['member_id' => $friend->getKey(), 'friend_id' => $viewer->getKey()],
        ]);
        $group = Group::factory()->create(['file_id' => File::factory()->create()->getKey()]);
        GroupMember::factory()->member()->create(['group_id' => $group->getKey(), 'member_id' => $viewer->getKey()]);

        // The rail's tiles paint at ~90px, well above every other surface's avatar, so both grids
        // ask for 180 rather than the 120 the small avatars use.
        $expectedFace = $friend->fresh()->avatar->file->thumbnailUrl(180, 180, square: true);
        $expectedGroup = $group->image->thumbnailUrl(180, 180, square: true);

        $this->actingAs($viewer)
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('rightRail.people.items.0.imageUrl', $expectedFace)
                ->where('rightRail.joinedGroups.0.imageUrl', $expectedGroup)
            );
    }

    public function test_rail_is_empty_for_a_member_with_no_friends_or_communities(): void
    {
        $this->actingAs(Member::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('rightRail.people.kind', 'friends')
                ->where('rightRail.people.items', [])
                ->where('rightRail.joinedGroups', [])
            );
    }

    public function test_rail_is_absent_for_a_guest(): void
    {
        config()->set('openpne.surface_mode', 'modern_only');

        $this->get('/login')->assertInertia(fn ($page) => $page->where('rightRail', null));
    }
}

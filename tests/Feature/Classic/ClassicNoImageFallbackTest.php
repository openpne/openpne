<?php

namespace Tests\Feature\Classic;

use App\Features\Member\Actions\SetAvatar;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * OpenPNE 3 parity: an unset member/community image renders the vendored no_image.gif rather than
 * nothing. The fallback flows through the shared x-classic.image component across Classic screens.
 */
class ClassicNoImageFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_profile_shows_the_no_image_fallback_when_the_owner_has_no_avatar(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get(route('member.profile.show', $owner))
            ->assertOk()
            ->assertSee('images/no_image.gif', false);
    }

    public function test_member_profile_with_an_avatar_shows_the_thumbnail_not_the_fallback(): void
    {
        $owner = Member::factory()->create();
        app(SetAvatar::class)($owner, UploadedFile::fake()->image('me.png', 100, 100));
        $file = $owner->fresh()->avatar->file;
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get(route('member.profile.show', $owner))
            ->assertOk()
            ->assertSee($file->thumbnailUrl(120, 120, square: true), false)
            ->assertDontSee('images/no_image.gif', false);
    }

    public function test_member_search_results_show_the_no_image_fallback_for_an_avatar_less_member(): void
    {
        $viewer = Member::factory()->create();
        Member::factory()->create(['name' => 'Avatarless Member']);

        $this->actingAs($viewer)->get('/member/search')
            ->assertOk()
            ->assertSee('Avatarless Member')
            ->assertSee('images/no_image.gif', false);
    }

    public function test_friend_photo_table_shows_the_thumbnail_for_one_friend_and_the_fallback_for_another(): void
    {
        $owner = Member::factory()->create();
        $withAvatar = Member::factory()->create();
        $withoutAvatar = Member::factory()->create();
        app(SetAvatar::class)($withAvatar, UploadedFile::fake()->image('me.png', 100, 100));
        foreach ([$withAvatar, $withoutAvatar] as $friend) {
            DB::table('friendships')->insert([
                ['member_id' => $owner->getKey(), 'friend_id' => $friend->getKey()],
                ['member_id' => $friend->getKey(), 'friend_id' => $owner->getKey()],
            ]);
        }

        $this->actingAs($owner)->get('/friend/list')
            ->assertOk()
            ->assertSee($withAvatar->fresh()->avatar->file->thumbnailUrl(76, 76, square: true), false)
            ->assertSee('images/no_image.gif', false);
    }

    public function test_community_home_shows_the_no_image_fallback_for_the_image_box_and_member_grid(): void
    {
        $group = Group::factory()->create(); // no top image
        $admin = Member::factory()->create();        // no avatar
        GroupMember::factory()->admin()->create([
            'group_id' => $group->getKey(),
            'member_id' => $admin->getKey(),
        ]);

        // The community image box and the nineTable member grid both fall back to no_image.gif.
        $this->actingAs($admin)->get(route('group.show', $group))
            ->assertOk()
            ->assertSee('images/no_image.gif', false);
    }
}

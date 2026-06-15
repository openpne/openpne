<?php

namespace Tests\Feature\Profile;

use App\Models\Gadget;
use App\Models\GadgetConfig;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Services\GadgetService;
use App\Services\SnsSettingService;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClassicProfileGadgetTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, string> $config */
    private function makeGadget(string $zone, string $name, array $config = []): Gadget
    {
        $gadget = Gadget::create(['context' => 'profile', 'zone' => $zone, 'name' => $name, 'sort_order' => 0]);
        foreach ($config as $key => $value) {
            GadgetConfig::create(['gadget_id' => $gadget->id, 'name' => $key, 'value' => $value]);
        }
        app(GadgetService::class)->clearCache();

        return $gadget;
    }

    public function test_profile_list_box_gadget_replaces_the_fixed_box(): void
    {
        $owner = Member::factory()->create(['name' => 'Owner']);
        $viewer = Member::factory()->create();
        $this->fieldFor($owner, Visibility::Members, 'a-visible-value');
        $this->makeGadget('contents', 'profileListBox');

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('class="dparts listBox"', false) // OpenPNE 3 listBox parts wrapper
            ->assertSee('a-visible-value')
            ->assertDontSee('id="member_profile"', false); // the fixed fallback box is gone
    }

    public function test_subject_is_the_profile_owner_not_the_viewer(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $ownerFriend = Member::factory()->create(['name' => 'OwnerFriend']);
        $viewerFriend = Member::factory()->create(['name' => 'ViewerFriend']);
        $this->makeFriends($owner, $ownerFriend);
        $this->makeFriends($viewer, $viewerFriend);
        $this->makeGadget('sideMenu', 'friendListBox');

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('OwnerFriend')      // the viewed member's friends
            ->assertDontSee('ViewerFriend'); // not the viewer's
    }

    public function test_guest_sees_public_gadgets_but_not_members_only(): void
    {
        $owner = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $ownerFriend = Member::factory()->create(['name' => 'SecretFriend']);
        $this->makeFriends($owner, $ownerFriend);
        $this->webField($owner, 'public-profile-value');
        $this->makeGadget('contents', 'profileListBox'); // viewable by anyone
        $this->makeGadget('sideMenu', 'friendListBox');  // members-only

        $this->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('public-profile-value')
            ->assertDontSee('SecretFriend');
    }

    public function test_active_profile_layout_narrows_the_rendered_zones(): void
    {
        DB::table('sns_settings')->insert(['key' => 'gadget_profile_layout', 'value' => 'layoutC']);
        app(SnsSettingService::class)->clearCache();

        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->makeGadget('top', 'freeArea', ['value' => '<p>TopArea</p>']);
        $this->makeGadget('contents', 'freeArea', ['value' => '<p>BodyArea</p>']);

        // layoutC = [contents, bottom]: the top gadget is not rendered.
        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('<p>BodyArea</p>', false)
            ->assertDontSee('<p>TopArea</p>', false);
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }

    private function fieldFor(Member $owner, Visibility $visibility, string $value): void
    {
        $profile = Profile::factory()->create(['is_edit_public_flag' => true]);
        MemberProfile::factory()->create([
            'member_id' => $owner->getKey(), 'profile_id' => $profile->getKey(),
            'value' => $value, 'visibility' => $visibility,
        ]);
    }

    private function webField(Member $owner, string $value): void
    {
        $profile = Profile::factory()->create(['is_edit_public_flag' => true, 'is_public_web' => true]);
        MemberProfile::factory()->create([
            'member_id' => $owner->getKey(), 'profile_id' => $profile->getKey(),
            'value' => $value, 'visibility' => Visibility::Open,
        ]);
    }
}

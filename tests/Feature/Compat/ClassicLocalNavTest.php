<?php

namespace Tests\Feature\Compat;

use App\Models\Diary;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpenPNE 3 localNav contexts: the viewer's own pages render the `default` set; a page about
 * another member renders the `friend` set with that member's id threaded into its Home / Diary /
 * Friends links (OpenPNE 3 sf_nav_type=friend + sf_nav_id). Guests get no localNav (@auth).
 */
class ClassicLocalNavTest extends TestCase
{
    use RefreshDatabase;

    public function test_own_pages_render_the_default_localnav(): void
    {
        $viewer = Member::factory()->create();

        foreach (['/diary/listMember', '/friend/list', "/member/{$viewer->getKey()}"] as $url) {
            $this->actingAs($viewer)->get($url)
                ->assertOk()
                ->assertSee('<ul class="default">', false)
                ->assertDontSee('<ul class="friend">', false);
        }
    }

    public function test_another_members_pages_render_the_friend_localnav(): void
    {
        $viewer = Member::factory()->create();
        $other = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $other->getKey(), 'visibility' => Visibility::Members]);

        $urls = [
            "/diary/listMember/{$other->getKey()}",
            "/member/{$other->getKey()}",
            "/friend/list?id={$other->getKey()}",
            "/diary/{$diary->getKey()}", // show routes through markLocalNavSubject
        ];

        foreach ($urls as $url) {
            $this->actingAs($viewer)->get($url)
                ->assertOk()
                ->assertSee('<ul class="friend">', false)
                ->assertSee(route('member.profile.show', $other), false)
                ->assertSee(route('diary.list_member', $other), false)
                ->assertSee(route('friend.list', ['id' => $other->getKey()]), false);
        }
    }

    public function test_a_guest_on_a_web_public_profile_sees_no_localnav(): void
    {
        $owner = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $profile = Profile::factory()->create(['is_edit_public_flag' => true, 'is_public_web' => true]);
        MemberProfile::factory()->create([
            'member_id' => $owner->getKey(), 'profile_id' => $profile->getKey(),
            'value' => 'public-value', 'visibility' => Visibility::Open,
        ]);

        $this->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertDontSee('<ul class="default">', false)
            ->assertDontSee('<ul class="friend">', false);
    }
}

<?php

namespace Tests\Feature\Profile;

use App\Models\Diary;
use App\Models\Gadget;
use App\Models\GadgetConfig;
use App\Models\Member;
use App\Services\GadgetService;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The OpenPNE 3 profile diary gadget: diaryMemberList (the profile owner's recent diaries). */
class ClassicProfileDiaryGadgetTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, string|int> $config */
    private function makeGadget(string $name, array $config = []): Gadget
    {
        $gadget = Gadget::create(['context' => 'profile', 'zone' => 'contents', 'name' => $name, 'sort_order' => 0]);
        foreach ($config as $key => $value) {
            GadgetConfig::create(['gadget_id' => $gadget->id, 'name' => $key, 'value' => (string) $value]);
        }
        app(GadgetService::class)->clearCache();

        return $gadget;
    }

    public function test_renders_the_owners_diaries_with_no_dom_id(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        Diary::factory()->create(['member_id' => $owner->getKey(), 'title' => 'OwnerDiaryTitle', 'visibility' => Visibility::Members]);
        $this->makeGadget('diaryMemberList');

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('class="dparts homeRecentList"', false)
            ->assertDontSee('id="homeRecentList_', false)  // OpenPNE 3 emitted no id here
            ->assertSee('Recently Posted Diaries')          // h3
            ->assertSee('OwnerDiaryTitle (0)')
            ->assertSee("/diary/listMember/{$owner->getKey()}", false); // More link (owner-scoped)
    }

    public function test_is_dropped_when_the_owner_has_no_diaries(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->makeGadget('diaryMemberList');

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertDontSee('homeRecentList', false);
    }

    public function test_honors_the_max_config(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        Diary::factory()->create(['member_id' => $owner->getKey(), 'title' => 'OldOwnerDiary', 'visibility' => Visibility::Members, 'created_at' => '2026-01-01']);
        Diary::factory()->create(['member_id' => $owner->getKey(), 'title' => 'NewOwnerDiary', 'visibility' => Visibility::Members, 'created_at' => '2026-03-01']);
        // OpenPNE 3 ignored the config here and always showed 5; OpenPNE 4 honors max.
        $this->makeGadget('diaryMemberList', ['max' => 1]);

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('NewOwnerDiary (0)')
            ->assertDontSee('OldOwnerDiary');
    }

    public function test_guest_does_not_see_the_members_only_gadget(): void
    {
        $owner = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        Diary::factory()->create(['member_id' => $owner->getKey(), 'title' => 'HiddenFromGuest', 'visibility' => Visibility::Members]);
        $this->makeGadget('diaryMemberList'); // members-only

        $this->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertDontSee('homeRecentList', false)
            ->assertDontSee('HiddenFromGuest');
    }
}

<?php

namespace Tests\Feature\Profile;

use App\Models\Gadget;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Services\GadgetService;
use App\Support\Visibility;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The OpenPNE 3 profile birthdayBox: greeting on the owner's birthday and the three days before. */
class ClassicProfileBirthdayGadgetTest extends TestCase
{
    use RefreshDatabase;

    private function makeGadget(): Gadget
    {
        $gadget = Gadget::create(['context' => 'profile', 'zone' => 'top', 'name' => 'birthdayBox', 'sort_order' => 0]);
        app(GadgetService::class)->clearCache();

        return $gadget;
    }

    private function giveBirthday(Member $member, string $date, Visibility $visibility = Visibility::Members): void
    {
        $profile = Profile::factory()->preset('birthday')->create(['form_type' => 'date', 'is_edit_public_flag' => true]);
        MemberProfile::factory()->create([
            'member_id' => $member->getKey(),
            'profile_id' => $profile->getKey(),
            'value' => $date,
            'value_datetime' => $date.' 00:00:00',
            'visibility' => $visibility,
        ]);
    }

    public function test_shows_the_day_image_on_the_owners_birthday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 09:00:00'));
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->giveBirthday($owner, '1990-06-24');
        $this->makeGadget();

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('<div class="parts birthday"><img', false) // single-layer DOM, no dparts wrapper/id
            ->assertSee('birthday_f.gif" alt="Happy Birthday!"', false);
    }

    public function test_shows_the_run_up_image_three_days_before(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-21 09:00:00'));
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->giveBirthday($owner, '1990-06-24');
        $this->makeGadget();

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertSee('birthday_f_2.gif" alt=""', false) // decorative: OpenPNE 3 emitted no alt; empty alt keeps it silent
            ->assertDontSee('birthday_f.gif"', false);
    }

    public function test_shows_nothing_four_days_before(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 09:00:00'));
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->giveBirthday($owner, '1990-06-24');
        $this->makeGadget();

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertDontSee('parts birthday', false);
    }

    public function test_drops_when_the_birthday_is_not_visible_to_the_viewer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 09:00:00'));
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create(); // non-friend
        $this->giveBirthday($owner, '1990-06-24', Visibility::Friends);
        $this->makeGadget();

        $this->actingAs($viewer)->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertDontSee('parts birthday', false);
    }

    public function test_guest_does_not_see_the_members_only_gadget(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 09:00:00'));
        $owner = Member::factory()->create(['profile_visibility' => Visibility::Open]);
        $this->giveBirthday($owner, '1990-06-24', Visibility::Open);
        $this->makeGadget();

        $this->get("/member/{$owner->getKey()}")
            ->assertOk()
            ->assertDontSee('parts birthday', false);
    }
}

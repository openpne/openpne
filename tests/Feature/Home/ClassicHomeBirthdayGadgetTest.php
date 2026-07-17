<?php

namespace Tests\Feature\Home;

use App\Models\Gadget;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Services\GadgetService;
use App\Support\Visibility;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The OpenPNE 3 home birthdayBox: a greeting image shown only on the viewer's own birthday. */
class ClassicHomeBirthdayGadgetTest extends TestCase
{
    use RefreshDatabase;

    private function makeGadget(): Gadget
    {
        $gadget = Gadget::create(['context' => 'home', 'zone' => 'top', 'name' => 'birthdayBox', 'sort_order' => 0]);
        app(GadgetService::class)->clearCache();

        return $gadget;
    }

    private function giveBirthday(Member $member, string $date): void
    {
        $profile = Profile::factory()->preset('birthday')->create(['form_type' => 'date']);
        MemberProfile::factory()->create([
            'member_id' => $member->getKey(),
            'profile_id' => $profile->getKey(),
            'value' => $date,
            'value_datetime' => $date.' 00:00:00',
            'visibility' => Visibility::Members,
        ]);
    }

    public function test_shows_the_greeting_on_the_members_own_birthday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 09:00:00'));
        $viewer = Member::factory()->create();
        $this->giveBirthday($viewer, '1990-06-24');
        $this->makeGadget();

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertSee('<div class="parts birthday"><img', false) // single-layer DOM, no dparts wrapper/id
            ->assertSee('images/birthday_h.gif', false)
            ->assertSee('alt="Happy Birthday!"', false);
    }

    public function test_shows_nothing_the_day_before(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-23 09:00:00'));
        $viewer = Member::factory()->create();
        $this->giveBirthday($viewer, '1990-06-24');
        $this->makeGadget();

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertDontSee('parts birthday', false)
            ->assertDontSee('birthday_h.gif', false);
    }

    public function test_shows_nothing_the_day_after(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 09:00:00'));
        $viewer = Member::factory()->create();
        $this->giveBirthday($viewer, '1990-06-24');
        $this->makeGadget();

        $this->actingAs($viewer)->get('/')
            ->assertOk()
            ->assertDontSee('parts birthday', false);
    }

    public function test_guest_does_not_see_the_members_only_gadget(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-24 09:00:00'));
        $this->makeGadget();

        $this->get('/')->assertRedirect('/login');
    }
}

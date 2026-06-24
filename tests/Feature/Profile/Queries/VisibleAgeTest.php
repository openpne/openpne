<?php

namespace Tests\Feature\Profile\Queries;

use App\Features\Profile\Queries\VisibleAge;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Support\PreferenceKey;
use App\Support\Visibility;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VisibleAgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-06-24'));
    }

    public function test_returns_null_when_the_owner_has_no_birthday(): void
    {
        $owner = Member::factory()->create();

        $this->assertNull($this->age($owner, $owner));
    }

    public function test_computes_the_age_in_whole_years(): void
    {
        $owner = Member::factory()->create();
        $this->giveBirthday($owner, '1990-06-23'); // birthday already passed this year

        $this->assertSame(36, $this->age($owner, $owner));
    }

    public function test_age_is_not_incremented_until_the_birthday(): void
    {
        $owner = Member::factory()->create();
        $this->giveBirthday($owner, '1990-06-25'); // birthday is tomorrow

        $this->assertSame(35, $this->age($owner, $owner));
    }

    public function test_guest_never_sees_age_even_when_open(): void
    {
        $owner = Member::factory()->create();
        $owner->setPreference(PreferenceKey::AgeVisibility, Visibility::Open);
        $this->giveBirthday($owner, '1990-06-23');

        $this->assertNull($this->age(null, $owner));                       // fail-closed for guests
        $this->assertSame(36, $this->age(Member::factory()->create(), $owner)); // a member still sees it
    }

    public function test_private_age_is_visible_only_to_self(): void
    {
        // Private is the default; no setPreference needed.
        $owner = Member::factory()->create();
        $friend = Member::factory()->create();
        $other = Member::factory()->create();
        $this->makeFriends($owner, $friend);
        $this->giveBirthday($owner, '1990-06-23');

        $this->assertSame(36, $this->age($owner, $owner)); // self (intentional divergence from OP3)
        $this->assertNull($this->age($friend, $owner));
        $this->assertNull($this->age($other, $owner));
        $this->assertNull($this->age(null, $owner));
    }

    public function test_members_age_is_visible_to_any_member_but_not_guests(): void
    {
        $owner = Member::factory()->create();
        $owner->setPreference(PreferenceKey::AgeVisibility, Visibility::Members);
        $this->giveBirthday($owner, '1990-06-23');

        $this->assertSame(36, $this->age(Member::factory()->create(), $owner));
        $this->assertNull($this->age(null, $owner));
    }

    public function test_a_blocked_viewer_never_sees_age(): void
    {
        $owner = Member::factory()->create();
        $owner->setPreference(PreferenceKey::AgeVisibility, Visibility::Members); // otherwise visible
        $viewer = Member::factory()->create();
        $this->giveBirthday($owner, '1990-06-23');
        DB::table('member_blocks')->insert(['blocker_id' => $owner->getKey(), 'blocked_id' => $viewer->getKey()]);

        $this->assertNull($this->age($viewer, $owner));
    }

    public function test_friends_age_is_visible_to_friends_only(): void
    {
        $owner = Member::factory()->create();
        $owner->setPreference(PreferenceKey::AgeVisibility, Visibility::Friends);
        $friend = Member::factory()->create();
        $other = Member::factory()->create();
        $this->makeFriends($owner, $friend);
        $this->giveBirthday($owner, '1990-06-23');

        $this->assertSame(36, $this->age($friend, $owner));
        $this->assertNull($this->age($other, $owner));
    }

    private function age(?Member $viewer, Member $owner): ?int
    {
        return app(VisibleAge::class)($viewer, $owner);
    }

    private function giveBirthday(Member $member, string $date): void
    {
        $profile = Profile::factory()->create(['name' => 'op_preset_birthday', 'form_type' => 'date']);
        MemberProfile::factory()->create([
            'member_id' => $member->getKey(),
            'profile_id' => $profile->getKey(),
            'value' => $date,
            'value_datetime' => $date.' 00:00:00',
        ]);
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }
}

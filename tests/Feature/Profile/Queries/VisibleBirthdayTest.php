<?php

namespace Tests\Feature\Profile\Queries;

use App\Features\Profile\Queries\VisibleBirthday;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Support\PreferenceKey;
use App\Support\Visibility;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VisibleBirthdayTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_when_the_owner_has_no_birthday_field(): void
    {
        $owner = Member::factory()->create();

        $this->assertNull($this->birthday($owner, $owner));
    }

    public function test_returns_null_when_the_birthday_field_has_no_value(): void
    {
        $owner = Member::factory()->create();
        $profile = Profile::factory()->preset('birthday')->create(['form_type' => 'date']);
        MemberProfile::factory()->create([
            'member_id' => $owner->getKey(),
            'profile_id' => $profile->getKey(),
            'value_datetime' => null,
        ]);

        $this->assertNull($this->birthday($owner, $owner));
    }

    public function test_returns_the_birthday_within_the_fields_own_visibility(): void
    {
        $owner = Member::factory()->create();
        $friend = Member::factory()->create();
        $stranger = Member::factory()->create();
        $this->makeFriends($owner, $friend);
        $this->giveBirthday($owner, '1990-06-24', Visibility::Friends);

        $this->assertSame('1990-06-24', $this->birthday($owner, $owner)?->toDateString());   // self
        $this->assertSame('1990-06-24', $this->birthday($friend, $owner)?->toDateString());  // friend
        $this->assertNull($this->birthday($stranger, $owner));                               // non-friend clamped
    }

    public function test_returns_null_when_the_owner_blocks_the_viewer(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $this->giveBirthday($owner, '1990-06-24', Visibility::Members);
        DB::table('member_blocks')->insert(['blocker_id' => $owner->getKey(), 'blocked_id' => $viewer->getKey()]);

        $this->assertNull($this->birthday($viewer, $owner));
    }

    public function test_age_gate_does_not_hide_a_visible_birthday(): void
    {
        $owner = Member::factory()->create();
        $owner->setPreference(PreferenceKey::AgeVisibility, Visibility::Private);
        $viewer = Member::factory()->create();
        $this->giveBirthday($owner, '1990-06-24', Visibility::Members);

        $this->assertSame('1990-06-24', $this->birthday($viewer, $owner)?->toDateString());
    }

    private function birthday(?Member $viewer, Member $owner): ?CarbonInterface
    {
        return app(VisibleBirthday::class)($viewer, $owner);
    }

    private function giveBirthday(Member $member, string $date, Visibility $visibility): void
    {
        $profile = Profile::factory()->preset('birthday')->create([
            'form_type' => 'date',
            'is_edit_public_flag' => true,
        ]);
        MemberProfile::factory()->create([
            'member_id' => $member->getKey(),
            'profile_id' => $profile->getKey(),
            'value' => $date,
            'value_datetime' => $date.' 00:00:00',
            'visibility' => $visibility,
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

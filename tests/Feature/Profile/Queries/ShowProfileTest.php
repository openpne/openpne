<?php

namespace Tests\Feature\Profile\Queries;

use App\Features\Profile\Data\ProfileFieldValue;
use App\Features\Profile\Queries\ShowProfile;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Models\ProfileOption;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShowProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_every_field(): void
    {
        $owner = Member::factory()->create();
        $this->seedAllLevels($owner);

        $this->assertCount(4, $this->show($owner, $owner));
    }

    public function test_friend_sees_open_members_and_friends_not_private(): void
    {
        [$owner, $friend] = Member::factory()->count(2)->create()->all();
        $this->makeFriends($owner, $friend);
        $this->seedAllLevels($owner);

        $this->assertCount(3, $this->show($friend, $owner));
    }

    public function test_non_friend_member_sees_only_open_and_members(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        $this->seedAllLevels($owner);

        $this->assertCount(2, $this->show($other, $owner));
    }

    public function test_blocked_viewer_gets_null(): void
    {
        [$owner, $viewer] = Member::factory()->count(2)->create()->all();
        $this->field($owner, Visibility::Members);
        DB::table('member_blocks')->insert(['blocker_id' => $owner->getKey(), 'blocked_id' => $viewer->getKey()]);

        $this->assertNull($this->show($viewer, $owner));
    }

    public function test_non_editable_field_uses_the_field_default_not_the_value_flag(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        // Field is not per-value editable and defaults to Private, so the Open value flag is ignored.
        $profile = Profile::factory()->create(['is_edit_public_flag' => false, 'default_visibility' => Visibility::Private]);
        MemberProfile::factory()->create([
            'member_id' => $owner->getKey(), 'profile_id' => $profile->getKey(), 'visibility' => Visibility::Open,
        ]);

        $this->assertCount(0, $this->show($other, $owner));   // hidden from a non-friend
        $this->assertCount(1, $this->show($owner, $owner));   // owner still sees it
    }

    public function test_checkbox_values_group_into_one_field(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        $profile = Profile::factory()->create(['form_type' => 'checkbox', 'is_edit_public_flag' => true]);
        foreach (['読書', '音楽'] as $label) {
            $option = ProfileOption::factory()->create(['profile_id' => $profile->getKey()]);
            $option->setLabel('ja_JP', $label);
            MemberProfile::factory()->create([
                'member_id' => $owner->getKey(), 'profile_id' => $profile->getKey(),
                'profile_option_id' => $option->getKey(), 'value' => '', 'visibility' => Visibility::Members,
            ]);
        }

        $result = $this->show($other, $owner);

        $this->assertCount(1, $result);
        $this->assertSame('読書, 音楽', $result->first()->display('ja_JP'));
    }

    public function test_fields_with_an_empty_value_are_skipped(): void
    {
        $owner = Member::factory()->create();
        $filled = Profile::factory()->create(['is_edit_public_flag' => true]);
        MemberProfile::factory()->create(['member_id' => $owner->getKey(), 'profile_id' => $filled->getKey(), 'value' => 'present', 'visibility' => Visibility::Members]);
        $empty = Profile::factory()->create(['is_edit_public_flag' => true]);
        MemberProfile::factory()->create(['member_id' => $owner->getKey(), 'profile_id' => $empty->getKey(), 'value' => '', 'visibility' => Visibility::Members]);

        $result = $this->show($owner, $owner);

        $this->assertCount(1, $result);
        $this->assertSame($filled->getKey(), $result->first()->profile->getKey());
    }

    /** @return Collection<int, ProfileFieldValue>|null */
    private function show(Member $viewer, Member $owner): ?Collection
    {
        return (new ShowProfile)($viewer, $owner, 'ja_JP');
    }

    private function seedAllLevels(Member $owner): void
    {
        foreach ([Visibility::Open, Visibility::Members, Visibility::Friends, Visibility::Private] as $level) {
            $this->field($owner, $level);
        }
    }

    private function field(Member $owner, Visibility $visibility): void
    {
        $profile = Profile::factory()->create(['is_edit_public_flag' => true]);
        MemberProfile::factory()->create([
            'member_id' => $owner->getKey(), 'profile_id' => $profile->getKey(),
            'value' => 'x', 'visibility' => $visibility,
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

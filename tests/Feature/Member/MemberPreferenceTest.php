<?php

namespace Tests\Feature\Member;

use App\Models\Member;
use App\Support\PreferenceKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unset_preference_reads_the_key_default(): void
    {
        $member = Member::factory()->create();

        $this->assertSame(Visibility::Members, $member->preference(PreferenceKey::DiaryDefaultVisibility));
        $this->assertSame(Visibility::Private, $member->preference(PreferenceKey::AgeVisibility));
    }

    public function test_set_persists_and_reads_back_without_a_reload(): void
    {
        $member = Member::factory()->create();

        $member->setPreference(PreferenceKey::DiaryDefaultVisibility, Visibility::Friends);

        // No fresh(): setPreference invalidates the cached relation, so the next read reloads.
        $this->assertSame(Visibility::Friends, $member->preference(PreferenceKey::DiaryDefaultVisibility));
        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'diary_default_visibility', 'value' => '2',
        ]);
    }

    public function test_setting_again_overwrites_the_single_row(): void
    {
        $member = Member::factory()->create();

        $member->setPreference(PreferenceKey::DiaryDefaultVisibility, Visibility::Friends);
        $member->setPreference(PreferenceKey::DiaryDefaultVisibility, Visibility::Private);

        $this->assertSame(1, $member->preferences()->where('key', 'diary_default_visibility')->count());
        $this->assertSame(Visibility::Private, $member->preference(PreferenceKey::DiaryDefaultVisibility));
    }

    public function test_setting_the_default_value_stores_an_explicit_row(): void
    {
        $member = Member::factory()->create();

        // An explicit choice equal to the default is stored, not normalised away (OpenPNE 3
        // recorded the explicit choice). resetPreference is the way back to default-following.
        $member->setPreference(PreferenceKey::DiaryDefaultVisibility, Visibility::Members);

        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'diary_default_visibility', 'value' => '1',
        ]);
    }

    public function test_reset_drops_the_row_so_reads_follow_the_default(): void
    {
        $member = Member::factory()->create();
        $member->setPreference(PreferenceKey::DiaryDefaultVisibility, Visibility::Friends);

        $member->resetPreference(PreferenceKey::DiaryDefaultVisibility);

        $this->assertDatabaseMissing('member_preferences', [
            'member_id' => $member->id, 'key' => 'diary_default_visibility',
        ]);
        $this->assertSame(Visibility::Members, $member->preference(PreferenceKey::DiaryDefaultVisibility));
    }
}

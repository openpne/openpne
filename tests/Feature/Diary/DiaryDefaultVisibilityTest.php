<?php

namespace Tests\Feature\Diary;

use App\Features\Diary\DiaryVisibility;
use App\Models\Member;
use App\Support\PreferenceKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DiaryDefaultVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_for_falls_back_to_members_when_unset(): void
    {
        $member = Member::factory()->create();

        $this->assertSame(Visibility::Members, DiaryVisibility::defaultFor($member));
    }

    public function test_default_for_returns_the_stored_preference(): void
    {
        $member = Member::factory()->create();
        $member->setPreference(PreferenceKey::DiaryDefaultVisibility, Visibility::Friends);

        $this->assertSame(Visibility::Friends, DiaryVisibility::defaultFor($member->fresh()));
    }

    public function test_default_for_clamps_a_stored_open_when_web_public_is_disabled(): void
    {
        config(['openpne.diary.allow_web_public' => false]);
        $member = Member::factory()->create();
        $member->setPreference(PreferenceKey::DiaryDefaultVisibility, Visibility::Open);

        $this->assertSame(Visibility::Members, DiaryVisibility::defaultFor($member->fresh()));
    }

    public function test_new_form_pre_selects_the_member_default(): void
    {
        config(['openpne.diary.allow_web_public' => false]);
        $member = Member::factory()->create();
        $member->setPreference(PreferenceKey::DiaryDefaultVisibility, Visibility::Friends);

        $this->actingAs($member, 'member')
            ->get('/m/diary/new')
            ->assertInertia(fn (Assert $page) => $page
                ->component('diary/new')
                ->where('defaultVisibility', '2'));
    }
}

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

    public function test_new_form_renders_open_so_it_is_not_a_hidden_choice(): void
    {
        // With web-public enabled and an Open default, Modern must both pre-select '0' AND
        // render the Open option — never submit Open from a select that does not show it.
        config(['openpne.diary.allow_web_public' => true]);
        $member = Member::factory()->create();
        $member->setPreference(PreferenceKey::DiaryDefaultVisibility, Visibility::Open);

        $this->actingAs($member, 'member')
            ->get('/m/diary/new')
            ->assertInertia(fn (Assert $page) => $page
                ->component('diary/new')
                ->where('defaultVisibility', '0')
                ->where('visibilityOptions.0.value', '0')); // Open leads the list
    }
}

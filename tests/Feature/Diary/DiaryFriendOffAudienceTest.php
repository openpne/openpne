<?php

namespace Tests\Feature\Diary;

use App\Models\Diary;
use App\Models\Member;
use App\Support\Feature;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DiaryFriendOffAudienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setSnsSetting(SnsSettingKey::DiaryAllowWebPublic, false);
        $this->setSnsSetting(Feature::Friend->settingKey(), false);
    }

    private function diaryAt(Member $member, Visibility $visibility): Diary
    {
        return Diary::factory()->create(['member_id' => $member->getKey(), 'visibility' => $visibility]);
    }

    /** @return array<string, string> */
    private function unchangedPayload(Diary $diary): array
    {
        return [
            'title' => $diary->title,
            'body' => $diary->body,
            'visibility' => (string) $diary->visibility->value,
        ];
    }

    public function test_the_classic_compose_form_does_not_offer_friends(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/diary/new')
            ->assertOk()
            ->assertSee('<option value="1"', false)
            ->assertDontSee('<option value="2"', false);
    }

    public function test_the_modern_compose_form_does_not_offer_friends(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/diary/new')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('diary/new')
                ->where('visibilityOptions', fn ($options) => collect($options)->pluck('value')->all() === ['1', '3']));
    }

    public function test_posting_a_new_entry_to_friends_is_rejected(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/diary/create', [
            'title' => 'Title',
            'body' => 'Body',
            'visibility' => (string) Visibility::Friends->value,
        ])->assertSessionHasErrors('visibility');

        $this->assertDatabaseCount('diaries', 0);
    }

    public function test_the_classic_edit_form_keeps_the_stored_friends_option(): void
    {
        $member = Member::factory()->create();
        $diary = $this->diaryAt($member, Visibility::Friends);

        $this->actingAs($member)->get("/diary/edit/{$diary->getKey()}")
            ->assertOk()
            ->assertSee('<option value="2" selected>', false);
    }

    public function test_the_modern_edit_form_keeps_the_stored_friends_option(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();
        $diary = $this->diaryAt($member, Visibility::Friends);

        $this->actingAs($member)->get("/diary/edit/{$diary->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('diary/edit')
                ->where('visibility', '2')
                ->where('visibilityOptions', fn ($options) => collect($options)->pluck('value')->all() === ['1', '2', '3']));
    }

    public function test_an_unchanged_edit_saves_the_stored_friends_back(): void
    {
        $member = Member::factory()->create();
        $diary = $this->diaryAt($member, Visibility::Friends);

        $this->actingAs($member)
            ->post("/diary/update/{$diary->getKey()}", $this->unchangedPayload($diary))
            ->assertSessionHasNoErrors();

        $this->assertSame(Visibility::Friends, $diary->fresh()->visibility);
    }

    public function test_editing_the_body_leaves_the_stored_friends_alone(): void
    {
        $member = Member::factory()->create();
        $diary = $this->diaryAt($member, Visibility::Friends);

        $this->actingAs($member)->post("/diary/update/{$diary->getKey()}", [
            ...$this->unchangedPayload($diary),
            'body' => 'Rewritten body',
        ])->assertSessionHasNoErrors();

        $fresh = $diary->fresh();
        $this->assertSame('Rewritten body', $fresh->body);
        $this->assertSame(Visibility::Friends, $fresh->visibility);
    }

    public function test_moving_off_friends_is_still_allowed(): void
    {
        $member = Member::factory()->create();
        $diary = $this->diaryAt($member, Visibility::Friends);

        $this->actingAs($member)->post("/diary/update/{$diary->getKey()}", [
            ...$this->unchangedPayload($diary),
            'visibility' => (string) Visibility::Members->value,
        ])->assertSessionHasNoErrors();

        $this->assertSame(Visibility::Members, $diary->fresh()->visibility);
    }

    public function test_an_entry_stored_elsewhere_cannot_be_moved_to_friends(): void
    {
        $member = Member::factory()->create();
        $diary = $this->diaryAt($member, Visibility::Members);

        $this->actingAs($member)->post("/diary/update/{$diary->getKey()}", [
            ...$this->unchangedPayload($diary),
            'visibility' => (string) Visibility::Friends->value,
        ])->assertSessionHasErrors('visibility');

        $this->assertSame(Visibility::Members, $diary->fresh()->visibility);
    }

    public function test_the_unit_switched_on_offers_friends_to_every_entry(): void
    {
        $this->setSnsSetting(Feature::Friend->settingKey(), true);
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();
        $diary = $this->diaryAt($member, Visibility::Members);

        $this->actingAs($member)->get('/diary/new')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('visibilityOptions', fn ($options) => collect($options)->pluck('value')->all() === ['1', '2', '3']));

        $this->actingAs($member)->post("/diary/update/{$diary->getKey()}", [
            ...$this->unchangedPayload($diary),
            'visibility' => (string) Visibility::Friends->value,
        ])->assertSessionHasNoErrors();

        $this->assertSame(Visibility::Friends, $diary->fresh()->visibility);
    }
}

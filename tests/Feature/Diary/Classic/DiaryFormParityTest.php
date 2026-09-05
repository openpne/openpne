<?php

namespace Tests\Feature\Diary\Classic;

use App\Models\Diary;
use App\Models\Member;
use App\Support\SnsSettingKey;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Locks the new/edit form elements openpne:screen-parity marks Ported, which the inventory leans on. */
class DiaryFormParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_form_offers_the_members_friends_private_visibility_choices(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/diary/new')
            ->assertOk()
            ->assertSee('name="visibility"', false) // the Ported visibility choice
            ->assertSee('All members')              // Visibility::Members
            ->assertSee('Private');                 // Visibility::Private
    }

    public function test_new_form_offers_web_public_when_the_gate_is_enabled(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/diary/new')
            ->assertOk()
            ->assertSee('Anyone on the web'); // Visibility::Open->label(), default-on gate
    }

    public function test_new_form_hides_web_public_when_the_gate_is_disabled(): void
    {
        $this->setSnsSetting(SnsSettingKey::DiaryAllowWebPublic, false);
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/diary/new')
            ->assertOk()
            ->assertDontSee('Anyone on the web');
    }

    public function test_edit_form_preselects_the_diary_visibility(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Private]);

        $this->actingAs($owner)->get("/diary/edit/{$diary->getKey()}")
            ->assertOk()
            ->assertSee('value="3" selected', false); // Private preselected
    }
}

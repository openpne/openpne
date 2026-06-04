<?php

namespace Tests\Feature\Diary\Classic;

use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the Ported elements of the diary new/edit form that screen-parity tracks, including the
 * web-public audience and its openpne.diary.allow_web_public gate (OpenPNE 3 lets a site disable
 * web-public diaries; that capability must survive).
 */
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
            ->assertSee('Public to Web'); // Visibility::Open->label(), default-on gate
    }

    public function test_new_form_hides_web_public_when_the_gate_is_disabled(): void
    {
        config(['openpne.diary.allow_web_public' => false]);
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/diary/new')
            ->assertOk()
            ->assertDontSee('Public to Web');
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

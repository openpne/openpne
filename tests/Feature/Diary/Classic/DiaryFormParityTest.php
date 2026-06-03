<?php

namespace Tests\Feature\Diary\Classic;

use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the Ported elements of the diary new/edit form that screen-parity tracks, and pins the
 * web-public gap: OpenPNE 3's visibility radio includes "All Users on the Web", but the form
 * offers only members/friends/private. When that gap is closed, the absence assertion fails and
 * prompts flipping the inventory element from missing to ported.
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

    public function test_new_form_does_not_yet_offer_the_web_public_option(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/diary/new')
            ->assertOk()
            ->assertDontSee('Public to Web'); // Visibility::Open->label() — the tracked gap
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

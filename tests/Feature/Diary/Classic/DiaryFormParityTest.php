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
            ->assertSee('Owner only');              // Visibility::Private
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
            ->assertSee('id="diary_public_flag_3" class="input_radio" checked', false); // Private preselected
    }

    public function test_the_visibility_radios_carry_the_public_flag_ids_in_the_order_openpne3_listed_them(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/diary/new');

        $response->assertOk();
        $response->assertSeeInOrder([
            '<ul class="radio_list">',
            '<input type="radio" name="visibility" value="0" id="diary_public_flag_4" class="input_radio"',
            '<label for="diary_public_flag_4">Anyone on the web</label>',
            'id="diary_public_flag_1" class="input_radio" checked',
            'id="diary_public_flag_2" class="input_radio"',
            'id="diary_public_flag_3" class="input_radio"',
        ], false);
        $response->assertDontSee('<select', false);
    }

    public function test_the_form_marks_title_body_and_visibility_required_and_prints_the_notice(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/diary/new');

        $response->assertOk();
        $response->assertSee('<strong>*</strong> is required field.', false);
        $response->assertSee('<label for="diary_title">Title <strong>*</strong>', false);
        $response->assertSee('<label for="diary_body">Body <strong>*</strong>', false);
        $response->assertSee('<label>Visibility <strong>*</strong>', false);
    }

    public function test_the_create_form_has_one_labelled_photo_row_per_slot(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/diary/new');

        $response->assertOk();
        foreach ([1, 2, 3] as $n) {
            $response->assertSeeInOrder([
                '<label for="diary_photo_'.$n.'_photo">Photo '.$n.'</label>',
                '<ul id="diary_photo_'.$n.'">',
                '<input type="file" name="images[]" id="diary_photo_'.$n.'_photo"',
            ], false);
        }
        $response->assertDontSee('name="remove_images[]"', false);
    }

    public function test_the_edit_form_shows_an_occupied_slot_as_its_photo_and_remove_checkbox(): void
    {
        $owner = Member::factory()->create();
        $diary = app(\App\Features\Diary\Actions\CreateDiary::class)($owner, new \App\Features\Diary\Data\DiaryFormData(title: 'T', body: 'B', visibility: Visibility::Members), [\Illuminate\Http\UploadedFile::fake()->image('i.png', 20, 20)]);
        $image = $diary->images()->with('file')->first();

        $response = $this->actingAs($owner)->get("/diary/edit/{$diary->getKey()}");

        $response->assertOk();
        $response->assertSeeInOrder([
            '<ul id="diary_photo_1">',
            $image->file->thumbnailUrl(120, 120, square: true),
            '<input type="checkbox" name="remove_images[]" value="'.$image->id.'" id="diary_photo_1_photo"',
            '<ul id="diary_photo_2">',
            '<input type="file" name="images[]" id="diary_photo_2_photo"',
            '<ul id="diary_photo_3">',
            '<input type="file" name="images[]" id="diary_photo_3_photo"',
        ], false);
        $this->assertSame(2, substr_count((string) $response->getContent(), 'name="images[]"'));
    }

    public function test_the_comment_form_names_its_box_and_photo_rows_as_openpne3_did(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'visibility' => Visibility::Members]);

        $response = $this->actingAs($owner)->get("/diary/{$diary->getKey()}");

        $response->assertOk();
        $response->assertSeeInOrder([
            '<strong>*</strong> is required field.',
            '<label for="diary_comment_body">Comment <strong>*</strong>',
            '<textarea id="diary_comment_body" name="body"',
            '<ul id="diary_comment_photo_1">',
            '<input type="file" name="images[]" id="diary_comment_photo_1_photo"',
            '<ul id="diary_comment_photo_2">',
            '<ul id="diary_comment_photo_3">',
        ], false);
    }
}

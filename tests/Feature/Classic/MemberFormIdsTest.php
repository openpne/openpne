<?php

namespace Tests\Feature\Classic;

use App\Models\Member;
use App\Models\Profile;
use App\Models\ProfileOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OpenPNE 3's form name formats (authMailAddress[%s], member_config[%s], profile[%s], member[%s])
 * gave every field the id a site's CSS reaches; the widgets' list and help markup came with them.
 */
class MemberFormIdsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_form_keeps_the_auth_mail_address_ids(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSeeInOrder([
            '<label for="authMailAddress_mail_address">',
            'id="authMailAddress_mail_address" name="email"',
            '<label for="authMailAddress_password">',
            'id="authMailAddress_password" name="password"',
            '<label for="authMailAddress_is_remember_me">',
            'id="authMailAddress_is_remember_me" name="remember"',
        ], false);
        // OpenPNE 3's login inputs carried no widget class; the skin reaches them through #MailAddressLogin.
        $response->assertDontSee('id="authMailAddress_mail_address" name="email" value="" class=', false);
        $response->assertDontSee('class="input_checkbox" id="authMailAddress_is_remember_me"', false);
    }

    public function test_the_invite_form_is_a_table_under_the_member_config_ids(): void
    {
        $response = $this->actingAs(Member::factory()->create())->get(route('member.invite'));

        $response->assertOk();
        $response->assertSeeInOrder([
            'id="inviteForm"',
            '<table>',
            '<th><label for="member_config_mail_address">',
            '<input type="email" class="input_text" id="member_config_mail_address" name="email"',
            '<th><label for="member_config_message">',
            '<textarea id="member_config_message" name="message"',
            '</table>',
        ], false);
        $response->assertDontSee('<dl>', false);
    }

    public function test_the_profile_edit_form_draws_a_checkbox_field_as_openpne3_did(): void
    {
        $profile = Profile::factory()->create(['name' => 'hobby', 'form_type' => 'checkbox', 'is_required' => true]);
        $profile->setTranslation('en', 'Hobby', 'Pick any.');
        [$a, $b] = [
            ProfileOption::factory()->create(['profile_id' => $profile->getKey()]),
            ProfileOption::factory()->create(['profile_id' => $profile->getKey()]),
        ];
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get(route('member.profile.edit'));

        $response->assertOk();
        $response->assertSeeInOrder([
            '<strong>*</strong> is required field.',
            '<label for="member_name">',
            '<strong>*</strong>',
            '<label for="profile_hobby_value">Hobby <strong>*</strong>',
            '<div class="input">',
            '<ul class="checkbox_list">',
            'id="profile_hobby_value_'.$a->getKey().'" class="input_checkbox"',
            '<label for="profile_hobby_value_'.$a->getKey().'">',
            'id="profile_hobby_value_'.$b->getKey().'" class="input_checkbox"',
            '<div class="publicFlag">',
            '<select id="profile_hobby_public_flag" name="visibility['.$profile->getKey().']" class="input_checkbox">',
            '<div class="help">Pick any.</div>',
        ], false);
        $response->assertDontSee('<p class="help">', false);
        $response->assertDontSee('class="required"', false);
    }

    public function test_the_profile_edit_form_draws_a_radio_field_as_a_radio_list_and_a_text_field_with_its_class(): void
    {
        $radio = Profile::factory()->create(['name' => 'blood', 'form_type' => 'radio', 'sort_order' => 1]);
        $radio->setTranslation('en', 'Blood');
        $option = ProfileOption::factory()->create(['profile_id' => $radio->getKey()]);
        $text = Profile::factory()->create(['name' => 'book', 'form_type' => 'input', 'sort_order' => 2]);
        $text->setTranslation('en', 'Book');

        $response = $this->actingAs(Member::factory()->create())->get(route('member.profile.edit'));

        $response->assertOk();
        $response->assertSeeInOrder([
            '<ul class="radio_list">',
            '<input type="radio" name="profile['.$radio->getKey().']" value="'.$option->getKey().'" id="profile_blood_value_'.$option->getKey().'" class="input_radio"',
            '<select id="profile_blood_public_flag" name="visibility['.$radio->getKey().']" class="input_radio">',
            '<input type="text" class="input_text" id="profile_book_value"',
            '<select id="profile_book_public_flag" name="visibility['.$text->getKey().']" class="input_text">',
        ], false);
    }

    public function test_a_select_field_leaves_its_audience_select_without_a_widget_class(): void
    {
        $select = Profile::factory()->create(['name' => 'blood_type', 'form_type' => 'select']);
        $select->setTranslation('en', 'Blood type');
        ProfileOption::factory()->create(['profile_id' => $select->getKey()]);

        $response = $this->actingAs(Member::factory()->create())->get(route('member.profile.edit'));

        $response->assertOk();
        $response->assertSee('<select id="profile_blood_type_public_flag" name="visibility['.$select->getKey().']">', false);
    }

    public function test_the_member_search_form_keeps_the_member_ids(): void
    {
        $sex = Profile::factory()->preset('sex')->create(['form_type' => 'select']);
        $sex->setTranslation('en', 'Sex');
        $intro = Profile::factory()->preset('self_introduction')->create(['form_type' => 'input']);
        $intro->setTranslation('en', 'Self Introduction');

        $response = $this->actingAs(Member::factory()->create())->get(route('member.search'));

        $response->assertOk();
        $response->assertSeeInOrder([
            '<label for="member_name">',
            '<input type="text" class="input_text" id="member_name" name="name"',
            '<label for="member_profile_op_preset_sex">',
            '<select id="member_profile_op_preset_sex" name="profile['.$sex->getKey().']">',
            '<label for="member_profile_op_preset_self_introduction">',
            '<input type="text" class="input_text" id="member_profile_op_preset_self_introduction" name="profile['.$intro->getKey().']"',
        ], false);
    }
}

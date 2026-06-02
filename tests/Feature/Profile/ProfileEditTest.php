<?php

namespace Tests\Feature\Profile;

use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Models\ProfileOption;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ProfileEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_reach_the_edit_page(): void
    {
        $this->get('/member/edit/profile')->assertRedirect('/login');
    }

    public function test_classic_edit_page_renders_fields_with_current_values(): void
    {
        $member = Member::factory()->create();
        $profile = Profile::factory()->create(['name' => 'fav_food', 'form_type' => 'input']);
        MemberProfile::factory()->create([
            'member_id' => $member->getKey(), 'profile_id' => $profile->getKey(), 'value' => 'ramen',
        ]);

        $this->actingAs($member)->get('/member/edit/profile')
            ->assertOk()
            ->assertSee('page_member_editProfile') // OpenPNE 3 body id from the route parity
            ->assertSee('fav_food')
            ->assertSee('ramen');
    }

    public function test_modern_edit_page_renders_the_form(): void
    {
        $member = Member::factory()->create();
        Profile::factory()->create(['form_type' => 'input']);

        $this->actingAs($member)->get('/m/member/edit/profile')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('member/edit-profile')
                ->where('form.name', $member->name)
                ->has('form.fields', 1)
            );
    }

    public function test_saves_a_text_value_and_the_member_name(): void
    {
        $member = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'input']);

        $this->actingAs($member)->post('/member/edit/profile', [
            'name' => 'New Nickname',
            'profile' => [$profile->getKey() => 'a typed value'],
        ])->assertRedirect('/member/edit/profile');

        $this->assertSame('New Nickname', $member->fresh()->name);
        $this->assertDatabaseHas('member_profiles', [
            'member_id' => $member->getKey(), 'profile_id' => $profile->getKey(), 'value' => 'a typed value',
        ]);
    }

    public function test_preset_select_stores_the_choice_key_in_value(): void
    {
        $member = Member::factory()->create();
        $profile = Profile::factory()->preset('sex')->create(['form_type' => 'select']);

        $this->save($member, [$profile->getKey() => 'Female']);

        $this->assertDatabaseHas('member_profiles', [
            'profile_id' => $profile->getKey(), 'value' => 'Female', 'profile_option_id' => null,
        ]);
    }

    public function test_custom_select_stores_the_option_id(): void
    {
        $member = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'select']);
        $option = ProfileOption::factory()->create(['profile_id' => $profile->getKey()]);

        $this->save($member, [$profile->getKey() => (string) $option->getKey()]);

        $this->assertDatabaseHas('member_profiles', [
            'profile_id' => $profile->getKey(), 'profile_option_id' => $option->getKey(), 'value' => null,
        ]);
    }

    public function test_checkbox_stores_one_row_per_chosen_option(): void
    {
        $member = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'checkbox']);
        $a = ProfileOption::factory()->create(['profile_id' => $profile->getKey()]);
        $b = ProfileOption::factory()->create(['profile_id' => $profile->getKey()]);

        $this->save($member, [$profile->getKey() => [(string) $a->getKey(), (string) $b->getKey()]]);

        $rows = MemberProfile::query()->where('profile_id', $profile->getKey())->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [$a->getKey(), $b->getKey()],
            $rows->pluck('profile_option_id')->all(),
        );
    }

    public function test_preset_date_stores_value_datetime_custom_date_stores_value(): void
    {
        $member = Member::factory()->create();
        $birthday = Profile::factory()->preset('birthday')->create(['form_type' => 'date']);
        $anniversary = Profile::factory()->create(['name' => 'anniversary', 'form_type' => 'date']);

        $this->save($member, [
            $birthday->getKey() => '1990-05-03',
            $anniversary->getKey() => '2000-01-02',
        ]);

        $birthdayRow = MemberProfile::query()->where('profile_id', $birthday->getKey())->first();
        $this->assertSame('1990-05-03', $birthdayRow->value_datetime?->format('Y-m-d'));
        $this->assertNull($birthdayRow->value);

        $anniversaryRow = MemberProfile::query()->where('profile_id', $anniversary->getKey())->first();
        $this->assertSame('2000-01-02', $anniversaryRow->value);
        $this->assertNull($anniversaryRow->value_datetime);
    }

    public function test_country_value_is_saved_and_renders_localised(): void
    {
        $member = Member::factory()->create();
        $profile = Profile::factory()->preset('country')->create(['form_type' => 'country_select']);

        $this->save($member, [$profile->getKey() => 'JP']);

        $row = MemberProfile::query()->where('profile_id', $profile->getKey())->first();
        $this->assertSame('JP', $row->value);
        $this->assertSame('日本', $row->displayValue('ja_JP'));
    }

    public function test_visibility_is_stored_for_an_editable_field(): void
    {
        $member = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'input', 'is_edit_public_flag' => true]);

        $this->actingAs($member)->post('/member/edit/profile', [
            'name' => $member->name,
            'profile' => [$profile->getKey() => 'x'],
            'visibility' => [$profile->getKey() => Visibility::Friends->value],
        ])->assertRedirect('/member/edit/profile');

        $this->assertDatabaseHas('member_profiles', [
            'profile_id' => $profile->getKey(), 'visibility' => Visibility::Friends->value,
        ]);
    }

    public function test_visibility_is_ignored_for_a_non_editable_field(): void
    {
        $member = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'input', 'is_edit_public_flag' => false]);

        $this->actingAs($member)->post('/member/edit/profile', [
            'name' => $member->name,
            'profile' => [$profile->getKey() => 'x'],
            'visibility' => [$profile->getKey() => Visibility::Open->value],
        ]);

        $row = MemberProfile::query()->where('profile_id', $profile->getKey())->first();
        $this->assertNull($row->visibility); // falls back to the field default in the read layer
    }

    public function test_empty_value_deletes_the_existing_row(): void
    {
        $member = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'input']);
        MemberProfile::factory()->create([
            'member_id' => $member->getKey(), 'profile_id' => $profile->getKey(), 'value' => 'old',
        ]);

        $this->save($member, [$profile->getKey() => '']);

        $this->assertDatabaseMissing('member_profiles', ['profile_id' => $profile->getKey()]);
    }

    public function test_required_field_rejects_an_empty_value(): void
    {
        $member = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'input', 'is_required' => true]);

        $this->actingAs($member)->post('/member/edit/profile', [
            'name' => $member->name,
            'profile' => [$profile->getKey() => ''],
        ])->assertSessionHasErrors("profile.{$profile->getKey()}");
    }

    public function test_value_regexp_rejects_a_non_matching_value(): void
    {
        $member = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'input', 'value_regexp' => '/^\d{3}-\d{4}$/']);

        $this->actingAs($member)->post('/member/edit/profile', [
            'name' => $member->name,
            'profile' => [$profile->getKey() => 'not-a-postcode'],
        ])->assertSessionHasErrors("profile.{$profile->getKey()}");

        $this->actingAs($member)->post('/member/edit/profile', [
            'name' => $member->name,
            'profile' => [$profile->getKey() => '123-4567'],
        ])->assertSessionHasNoErrors();
    }

    public function test_select_rejects_an_unknown_option(): void
    {
        $member = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'select']);
        ProfileOption::factory()->create(['profile_id' => $profile->getKey()]);

        $this->actingAs($member)->post('/member/edit/profile', [
            'name' => $member->name,
            'profile' => [$profile->getKey() => '999999'],
        ])->assertSessionHasErrors("profile.{$profile->getKey()}");
    }

    public function test_open_visibility_is_rejected_for_a_non_web_public_field(): void
    {
        $member = Member::factory()->create();
        $profile = Profile::factory()->create([
            'form_type' => 'input', 'is_edit_public_flag' => true, 'is_public_web' => false,
        ]);

        $this->actingAs($member)->post('/member/edit/profile', [
            'name' => $member->name,
            'profile' => [$profile->getKey() => 'x'],
            'visibility' => [$profile->getKey() => Visibility::Open->value],
        ])->assertSessionHasErrors("visibility.{$profile->getKey()}");
    }

    public function test_unique_field_rejects_a_value_held_by_another_member(): void
    {
        $member = Member::factory()->create();
        $other = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'input', 'is_unique' => true]);
        MemberProfile::factory()->create([
            'member_id' => $other->getKey(), 'profile_id' => $profile->getKey(), 'value' => 'taken',
        ]);

        $this->actingAs($member)->post('/member/edit/profile', [
            'name' => $member->name,
            'profile' => [$profile->getKey() => 'taken'],
        ])->assertSessionHasErrors("profile.{$profile->getKey()}");
    }

    public function test_unique_field_lets_a_member_resave_their_own_value(): void
    {
        $member = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'input', 'is_unique' => true]);
        MemberProfile::factory()->create([
            'member_id' => $member->getKey(), 'profile_id' => $profile->getKey(), 'value' => 'mine',
        ]);

        $this->actingAs($member)->post('/member/edit/profile', [
            'name' => $member->name,
            'profile' => [$profile->getKey() => 'mine'],
        ])->assertSessionHasNoErrors();
    }

    public function test_stale_open_visibility_on_a_non_web_field_is_normalised_to_members(): void
    {
        // is_public_web was turned off after the value was set web-public, leaving a stored Open
        // that the form can no longer offer; it must surface as Members, not an out-of-range value.
        $member = Member::factory()->create();
        $profile = Profile::factory()->create([
            'form_type' => 'input', 'is_edit_public_flag' => true, 'is_public_web' => false,
        ]);
        MemberProfile::factory()->create([
            'member_id' => $member->getKey(), 'profile_id' => $profile->getKey(),
            'value' => 'x', 'visibility' => Visibility::Open,
        ]);

        $this->actingAs($member)->get('/m/member/edit/profile')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('form.fields.0.visibility', Visibility::Members->value)
            );
    }

    public function test_date_field_enforces_the_admin_configured_min_and_max(): void
    {
        $member = Member::factory()->create();
        $profile = Profile::factory()->create([
            'name' => 'event_date', 'form_type' => 'date', 'value_min' => '2000-01-01', 'value_max' => '2020-12-31',
        ]);

        foreach (['1999-12-31', '2021-01-01'] as $outOfRange) {
            $this->actingAs($member)->post('/member/edit/profile', [
                'name' => $member->name,
                'profile' => [$profile->getKey() => $outOfRange],
            ])->assertSessionHasErrors("profile.{$profile->getKey()}");
        }

        $this->actingAs($member)->post('/member/edit/profile', [
            'name' => $member->name,
            'profile' => [$profile->getKey() => '2010-06-15'],
        ])->assertSessionHasNoErrors();
    }

    /** @param array<int, string|list<string>> $values */
    private function save(Member $member, array $values): void
    {
        $this->actingAs($member)->post('/member/edit/profile', [
            'name' => $member->name,
            'profile' => $values,
        ])->assertRedirect('/member/edit/profile');
    }
}

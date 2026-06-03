<?php

namespace Tests\Feature\Auth;

use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Models\ProfileOption;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
        $response->assertInertia(fn (AssertableInertia $page) => $page->component('auth/register'));
    }

    public function test_new_members_can_register(): void
    {
        $response = $this->register();

        $this->assertAuthenticated();
        $response->assertRedirect('/');

        $member = Member::where('email', 'test@example.com')->first();
        $this->assertNotNull($member);
        $this->assertSame('Test Member', $member->name);
        $this->assertTrue(Hash::check('password', $member->password));
    }

    public function test_registration_requires_unique_email(): void
    {
        Member::factory()->create(['email' => 'existing@example.com']);

        $response = $this->from('/register')->register(['email' => 'existing@example.com']);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_registration_requires_password_confirmation(): void
    {
        $response = $this->from('/register')->register(['password_confirmation' => 'different']);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_registration_screen_shows_only_registration_profile_fields(): void
    {
        $shown = Profile::factory()->create(['form_type' => 'input', 'is_disp_regist' => true]);
        Profile::factory()->create(['is_disp_regist' => false]);

        $this->get('/register')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/register')
            ->has('profileFields', 1)
            ->where('profileFields.0.id', $shown->getKey()));
    }

    public function test_new_member_can_register_with_a_profile_value(): void
    {
        $profile = Profile::factory()->create(['form_type' => 'input', 'is_edit_public_flag' => false, 'is_disp_regist' => true]);

        $this->register(['profile' => [$profile->getKey() => 'I love hiking']])->assertRedirect('/');

        $row = $this->storedRow($profile);
        $this->assertSame('I love hiking', $row->value);
        $this->assertNull($row->visibility); // not member-editable → follows the field default
    }

    public function test_member_can_choose_a_non_default_visibility_at_registration(): void
    {
        // The field defaults to Members, but the member picks Private while registering.
        $profile = Profile::factory()->create([
            'form_type' => 'input',
            'is_edit_public_flag' => true,
            'default_visibility' => Visibility::Members,
            'is_disp_regist' => true,
        ]);

        $this->register([
            'profile' => [$profile->getKey() => 'secret hobby'],
            'visibility' => [$profile->getKey() => Visibility::Private->value],
        ])->assertRedirect('/');

        $this->assertSame(Visibility::Private, $this->storedRow($profile)->visibility);
    }

    public function test_a_visibility_outside_the_fields_allowed_range_is_rejected(): void
    {
        // Not web-public → Open is not an offered choice, so it must not be accepted.
        $profile = Profile::factory()->create([
            'form_type' => 'input',
            'is_edit_public_flag' => true,
            'is_public_web' => false,
            'is_disp_regist' => true,
        ]);

        $this->from('/register')->register([
            'profile' => [$profile->getKey() => 'value'],
            'visibility' => [$profile->getKey() => Visibility::Open->value],
        ])->assertRedirect('/register')->assertSessionHasErrors("visibility.{$profile->getKey()}");
        $this->assertGuest();
    }

    public function test_a_posted_visibility_for_a_non_editable_field_is_ignored(): void
    {
        $profile = Profile::factory()->create(['form_type' => 'input', 'is_edit_public_flag' => false, 'is_disp_regist' => true]);

        $this->register([
            'profile' => [$profile->getKey() => 'value'],
            'visibility' => [$profile->getKey() => Visibility::Private->value],
        ])->assertRedirect('/');

        $this->assertNull($this->storedRow($profile)->visibility); // crafted visibility ignored
    }

    public function test_preset_select_value_is_stored_in_the_value_column(): void
    {
        $sex = Profile::factory()->preset('sex')->create(['form_type' => 'select', 'is_disp_regist' => true]);

        $this->register(['profile' => [$sex->getKey() => 'Female']])->assertRedirect('/');

        $row = $this->storedRow($sex);
        $this->assertSame('Female', $row->value);
        $this->assertNull($row->profile_option_id);
    }

    public function test_custom_select_value_is_stored_as_an_option_id(): void
    {
        $profile = Profile::factory()->create(['form_type' => 'select', 'is_disp_regist' => true]);
        $option = ProfileOption::factory()->create(['profile_id' => $profile->getKey()]);

        $this->register(['profile' => [$profile->getKey() => (string) $option->getKey()]])->assertRedirect('/');

        $this->assertSame($option->getKey(), $this->storedRow($profile)->profile_option_id);
    }

    public function test_checkbox_stores_one_row_per_chosen_option(): void
    {
        $profile = Profile::factory()->create(['form_type' => 'checkbox', 'is_disp_regist' => true]);
        $a = ProfileOption::factory()->create(['profile_id' => $profile->getKey()]);
        $b = ProfileOption::factory()->create(['profile_id' => $profile->getKey()]);
        ProfileOption::factory()->create(['profile_id' => $profile->getKey()]); // offered but not chosen

        $this->register(['profile' => [$profile->getKey() => [(string) $a->getKey(), (string) $b->getKey()]]])
            ->assertRedirect('/');

        $member = Member::where('email', 'test@example.com')->firstOrFail();
        $optionIds = MemberProfile::where('member_id', $member->getKey())
            ->where('profile_id', $profile->getKey())
            ->pluck('profile_option_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $this->assertSame([$a->getKey(), $b->getKey()], $optionIds);
    }

    public function test_a_required_registration_field_blocks_registration(): void
    {
        $profile = Profile::factory()->create(['form_type' => 'input', 'is_required' => true, 'is_disp_regist' => true]);

        $this->from('/register')->register()
            ->assertRedirect('/register')
            ->assertSessionHasErrors("profile.{$profile->getKey()}");
        $this->assertGuest();
    }

    public function test_a_non_registration_field_is_not_saved_even_if_posted(): void
    {
        $profile = Profile::factory()->create(['form_type' => 'input', 'is_disp_regist' => false]);

        $this->register(['profile' => [$profile->getKey() => 'crafted']])->assertRedirect('/');

        $member = Member::where('email', 'test@example.com')->firstOrFail();
        $this->assertDatabaseMissing('member_profiles', [
            'member_id' => $member->getKey(),
            'profile_id' => $profile->getKey(),
        ]);
    }

    public function test_a_unique_registration_field_rejects_a_value_another_member_holds(): void
    {
        $profile = Profile::factory()->create(['form_type' => 'input', 'is_unique' => true, 'is_disp_regist' => true]);
        $other = Member::factory()->create();
        MemberProfile::factory()->create([
            'member_id' => $other->getKey(),
            'profile_id' => $profile->getKey(),
            'value' => 'taken',
        ]);

        $this->from('/register')->register(['profile' => [$profile->getKey() => 'taken']])
            ->assertRedirect('/register')
            ->assertSessionHasErrors("profile.{$profile->getKey()}");
        $this->assertGuest();
    }

    /** @param array<string, mixed> $overrides */
    private function register(array $overrides = []): TestResponse
    {
        return $this->post('/register', array_merge([
            'name' => 'Test Member',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ], $overrides));
    }

    private function storedRow(Profile $profile): MemberProfile
    {
        $member = Member::where('email', 'test@example.com')->firstOrFail();

        return MemberProfile::where('member_id', $member->getKey())
            ->where('profile_id', $profile->getKey())
            ->firstOrFail();
    }
}

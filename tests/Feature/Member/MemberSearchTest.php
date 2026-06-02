<?php

namespace Tests\Feature\Member;

use App\Features\Member\Queries\SearchMembers;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Models\ProfileOption;
use App\Support\Visibility;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MemberSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_requires_login(): void
    {
        $this->get('/member/search')->assertRedirect('/login');
    }

    public function test_classic_search_renders_results(): void
    {
        $viewer = Member::factory()->create();
        Member::factory()->create(['name' => 'Findable Alice']);

        $this->actingAs($viewer)->get('/member/search')
            ->assertOk()
            ->assertSee('page_member_search')
            ->assertSee('Findable Alice');
    }

    public function test_modern_search_renders(): void
    {
        $viewer = Member::factory()->create();
        Member::factory()->create();

        $this->actingAs($viewer)->get('/m/member/search')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('member/search')
                ->has('members.data')
                ->has('profiles'));
    }

    public function test_name_keyword_filters(): void
    {
        $viewer = Member::factory()->create(['name' => 'Viewer']);
        $alice = Member::factory()->create(['name' => 'Alice Photographer']);
        Member::factory()->create(['name' => 'Bob Cook']);

        $ids = $this->ids($this->search($viewer, name: 'Photographer'));
        $this->assertSame([$alice->getKey()], $ids);
    }

    public function test_input_partial_match(): void
    {
        $viewer = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'input']);
        $match = $this->memberWithValue($profile, 'I love photography');
        $this->memberWithValue($profile, 'Cooking enthusiast');

        $this->assertSame([$match->getKey()], $this->ids($this->search($viewer, profile: [$profile->getKey() => 'photo'])));
    }

    public function test_preset_select_matches_the_choice_key(): void
    {
        $viewer = Member::factory()->create();
        $sex = Profile::factory()->preset('sex')->create(['form_type' => 'select']);
        $female = $this->memberWithValue($sex, 'Female');
        $this->memberWithValue($sex, 'Man');

        $this->assertSame([$female->getKey()], $this->ids($this->search($viewer, profile: [$sex->getKey() => 'Female'])));
    }

    public function test_custom_select_matches_the_option_id(): void
    {
        $viewer = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'select']);
        $red = ProfileOption::factory()->create(['profile_id' => $profile->getKey()]);
        $blue = ProfileOption::factory()->create(['profile_id' => $profile->getKey()]);
        $match = $this->memberWithOption($profile, $red);
        $this->memberWithOption($profile, $blue);

        $this->assertSame([$match->getKey()], $this->ids($this->search($viewer, profile: [$profile->getKey() => (string) $red->getKey()])));
    }

    public function test_checkbox_matches_any_chosen_option(): void
    {
        $viewer = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'checkbox']);
        $a = ProfileOption::factory()->create(['profile_id' => $profile->getKey()]);
        $b = ProfileOption::factory()->create(['profile_id' => $profile->getKey()]);
        $match = $this->memberWithOption($profile, $a);
        $this->memberWithOption($profile, $b);

        $this->assertSame([$match->getKey()], $this->ids($this->search($viewer, profile: [$profile->getKey() => [(string) $a->getKey()]])));
    }

    public function test_preset_date_range_matches_value_datetime(): void
    {
        $viewer = Member::factory()->create();
        $birthday = Profile::factory()->preset('birthday')->create(['form_type' => 'date']);
        $match = $this->memberWithValue($birthday, '', ['value_datetime' => '1990-05-03 00:00:00']);
        $this->memberWithValue($birthday, '', ['value_datetime' => '1975-01-01 00:00:00']);

        $hits = $this->ids($this->search($viewer, date: [$birthday->getKey() => ['from' => '1990-01-01', 'to' => '1990-12-31']]));
        $this->assertSame([$match->getKey()], $hits);
    }

    public function test_custom_date_range_matches_value(): void
    {
        $viewer = Member::factory()->create();
        $profile = Profile::factory()->create(['name' => 'anniversary', 'form_type' => 'date']);
        $match = $this->memberWithValue($profile, '2010-06-15');
        $this->memberWithValue($profile, '1999-01-01');

        $hits = $this->ids($this->search($viewer, date: [$profile->getKey() => ['from' => '2010-01-01', 'to' => '2010-12-31']]));
        $this->assertSame([$match->getKey()], $hits);
    }

    public function test_country_matches_the_code(): void
    {
        $viewer = Member::factory()->create();
        $profile = Profile::factory()->preset('country')->create(['form_type' => 'country_select']);
        $match = $this->memberWithValue($profile, 'JP');
        $this->memberWithValue($profile, 'US');

        $this->assertSame([$match->getKey()], $this->ids($this->search($viewer, profile: [$profile->getKey() => 'JP'])));
    }

    public function test_private_value_is_not_matched_for_a_non_owner_but_is_for_the_owner(): void
    {
        $viewer = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'input', 'is_edit_public_flag' => true]);
        $owner = $this->memberWithValue($profile, 'secret-hobby', ['visibility' => Visibility::Private]);

        $this->assertNotContains($owner->getKey(), $this->ids($this->search($viewer, profile: [$profile->getKey() => 'secret'])));
        $this->assertContains($owner->getKey(), $this->ids($this->search($owner, profile: [$profile->getKey() => 'secret'])));
    }

    public function test_friends_only_value_is_matched_only_for_a_friend(): void
    {
        $stranger = Member::factory()->create();
        $friend = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'input', 'is_edit_public_flag' => true]);
        $owner = $this->memberWithValue($profile, 'mutual-interest', ['visibility' => Visibility::Friends]);
        $this->makeFriends($owner, $friend);

        $this->assertNotContains($owner->getKey(), $this->ids($this->search($stranger, profile: [$profile->getKey() => 'mutual'])));
        $this->assertContains($owner->getKey(), $this->ids($this->search($friend, profile: [$profile->getKey() => 'mutual'])));
    }

    public function test_members_visible_value_is_matched_for_any_logged_in_member(): void
    {
        $viewer = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'input', 'is_edit_public_flag' => true]);
        $owner = $this->memberWithValue($profile, 'open-bio', ['visibility' => Visibility::Members]);

        $this->assertContains($owner->getKey(), $this->ids($this->search($viewer, profile: [$profile->getKey() => 'open-bio'])));
    }

    public function test_an_owner_who_blocks_the_viewer_is_excluded(): void
    {
        $viewer = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'input']);
        $owner = $this->memberWithValue($profile, 'blocked-value');
        DB::table('member_blocks')->insert(['blocker_id' => $owner->getKey(), 'blocked_id' => $viewer->getKey()]);

        $this->assertNotContains($owner->getKey(), $this->ids($this->search($viewer, profile: [$profile->getKey() => 'blocked'])));
    }

    public function test_a_forcibly_private_field_is_hidden_from_the_search_form(): void
    {
        $forced = Profile::factory()->create(['default_visibility' => Visibility::Private, 'is_edit_public_flag' => false, 'is_disp_search' => true]);
        $normal = Profile::factory()->create(['is_disp_search' => true]);

        $names = app(SearchMembers::class)->searchableProfiles()->pluck('id')->all();
        $this->assertNotContains($forced->getKey(), $names);
        $this->assertContains($normal->getKey(), $names);
    }

    /**
     * @param  array<int|string, mixed>  $profile
     * @param  array<int|string, mixed>  $date
     * @return LengthAwarePaginator<int, Member>
     */
    private function search(Member $viewer, string $name = '', array $profile = [], array $date = []): LengthAwarePaginator
    {
        return app(SearchMembers::class)($viewer, $name, $profile, $date);
    }

    /**
     * @param  LengthAwarePaginator<int, Member>  $paginator
     * @return list<int>
     */
    private function ids(LengthAwarePaginator $paginator): array
    {
        return collect($paginator->items())->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @param array<string, mixed> $extra */
    private function memberWithValue(Profile $profile, string $value, array $extra = []): Member
    {
        $member = Member::factory()->create();
        MemberProfile::factory()->create(array_merge([
            'member_id' => $member->getKey(),
            'profile_id' => $profile->getKey(),
            'value' => $value,
        ], $extra));

        return $member;
    }

    private function memberWithOption(Profile $profile, ProfileOption $option): Member
    {
        $member = Member::factory()->create();
        MemberProfile::factory()->create([
            'member_id' => $member->getKey(),
            'profile_id' => $profile->getKey(),
            'profile_option_id' => $option->getKey(),
            'value' => '',
        ]);

        return $member;
    }

    private function makeFriends(Member $a, Member $b): void
    {
        DB::table('friendships')->insert([
            ['member_id' => $a->getKey(), 'friend_id' => $b->getKey()],
            ['member_id' => $b->getKey(), 'friend_id' => $a->getKey()],
        ]);
    }
}

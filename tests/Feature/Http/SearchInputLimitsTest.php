<?php

namespace Tests\Feature\Http;

use App\Models\Diary;
use App\Models\Member;
use App\Models\Profile;
use App\Support\SearchInput;
use App\Support\SnsSettingKey;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SearchInputLimitsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function screens(): array
    {
        return [
            'diary keyword' => ['/diary/search', 'keyword'],
            'member name' => ['/member/search', 'name'],
            'group name (Modern)' => ['/groups', 'keyword'],
            'group name (Classic form)' => ['/groups', 'community[name]'],
        ];
    }

    #[DataProvider('screens')]
    public function test_an_overlong_field_returns_to_the_screen_with_the_reason(string $path, string $field): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get($path.'?'.http_build_query($this->field($field, str_repeat('あ', SearchInput::MAX_LENGTH + 1))))
            ->assertRedirect($path)
            ->assertSessionHas('error', 'Search with up to 100 characters.');

        $this->actingAs($member)->get($path.'?'.http_build_query($this->field($field, str_repeat('あ', SearchInput::MAX_LENGTH))))
            ->assertOk();
    }

    public function test_an_overlong_profile_value_returns_to_the_member_search(): void
    {
        $member = Member::factory()->create();
        $profile = Profile::factory()->create(['form_type' => 'input']);

        $this->actingAs($member)->get('/member/search?'.http_build_query(['profile' => [$profile->getKey() => str_repeat('a', SearchInput::MAX_LENGTH + 1)]]))
            ->assertRedirect('/member/search')
            ->assertSessionHas('error');
    }

    public function test_the_modern_diary_archive_keyword_is_capped_too(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $member = Member::factory()->create();
        $path = '/diary/listMember/'.$member->getKey();

        $this->actingAs($member)->get($path.'?keyword='.str_repeat('a', SearchInput::MAX_LENGTH + 1))
            ->assertRedirect($path)
            ->assertSessionHas('error');
    }

    public function test_the_ai_account_group_browser_is_capped_too(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();
        $path = route('member.config.ai.show', ['member' => Member::factory()->aiAccount($owner)->create()->getKey()], false);

        $this->actingAs($owner)->get($path.'?keyword='.str_repeat('a', SearchInput::MAX_LENGTH + 1))
            ->assertRedirect($path)
            ->assertSessionHas('error');
    }

    public function test_the_classic_screen_shows_the_reason_in_its_error_slot(): void
    {
        config(['openpne.surface_mode' => 'classic_default']);

        $this->actingAs(Member::factory()->create())->followingRedirects()
            ->get('/member/search?name='.str_repeat('a', SearchInput::MAX_LENGTH + 1))
            ->assertOk()
            ->assertSee('id="flashError"', false)
            ->assertSee('Search with up to 100 characters.');
    }

    public function test_the_modern_screen_gets_the_reason_as_the_flash_prop(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);

        $this->actingAs(Member::factory()->create())->followingRedirects()
            ->get('/groups?keyword='.str_repeat('a', SearchInput::MAX_LENGTH + 1))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('flash.error', 'Search with up to 100 characters.'));
    }

    public function test_an_inertia_visit_is_redirected_rather_than_answered_with_json(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml']);

        $this->actingAs(Member::factory()->create())
            ->get('/groups?keyword='.str_repeat('a', SearchInput::MAX_LENGTH + 1))
            ->assertRedirect('/groups');
    }

    /** The request reaches PHP as plain http, as it does behind a proxy that ends TLS and is not trusted. */
    public function test_the_way_back_keeps_a_forced_https_scheme(): void
    {
        URL::forceScheme('https');

        $location = $this->actingAs(Member::factory()->create())
            ->get('http://localhost/groups?keyword='.str_repeat('a', SearchInput::MAX_LENGTH + 1))
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertSame('https://localhost/groups', $location);
    }

    public function test_a_json_request_gets_a_422(): void
    {
        $this->actingAs(Member::factory()->create())
            ->getJson('/groups?keyword='.str_repeat('a', SearchInput::MAX_LENGTH + 1))
            ->assertStatus(422)
            ->assertJson(['message' => 'Search with up to 100 characters.']);
    }

    public function test_a_guest_diary_search_is_capped_too(): void
    {
        $this->setSnsSetting(SnsSettingKey::DiaryAllowWebPublic, true);

        $this->get('/diary/search?keyword='.str_repeat('a', SearchInput::MAX_LENGTH + 1))
            ->assertRedirect('/diary/search')
            ->assertSessionHas('error');
    }

    /** SQLite before 3.32 refuses a statement with more than 999 bound values, which the 3.26 CI lane runs. */
    public function test_the_most_diary_terms_allowed_run_and_one_more_is_refused(): void
    {
        $member = Member::factory()->create();
        Diary::factory()->create(['member_id' => $member->getKey(), 'title' => implode(' ', range(1, SearchInput::MAX_TERMS))]);
        $most = 0;
        DB::listen(function (QueryExecuted $query) use (&$most): void {
            $most = max($most, count($query->bindings));
        });

        $this->actingAs($member)->get('/diary/search?keyword='.urlencode(implode(' ', range(1, SearchInput::MAX_TERMS))))
            ->assertOk();
        $this->assertLessThan(999, $most);

        $this->actingAs($member)->get('/diary/search?keyword='.urlencode(implode('　', range(1, SearchInput::MAX_TERMS + 1))))
            ->assertRedirect('/diary/search')
            ->assertSessionHas('error', 'Search with up to 10 keywords.');
    }

    /** @return array<string, mixed> */
    private function field(string $field, string $value): array
    {
        parse_str($field.'='.urlencode($value), $query);

        return $query;
    }
}

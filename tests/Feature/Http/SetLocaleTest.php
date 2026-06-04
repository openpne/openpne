<?php

namespace Tests\Feature\Http;

use App\Http\Middleware\SetLocale;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SetLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_locale_outranks_the_session(): void
    {
        $member = Member::factory()->make(['locale' => 'en']);

        $this->assertSame('en', $this->resolve('member', $member, session: 'ja'));
    }

    public function test_session_is_used_when_the_member_has_no_locale(): void
    {
        $member = Member::factory()->make(['locale' => null]);

        $this->assertSame('ja', $this->resolve('member', $member, session: 'ja'));
    }

    public function test_an_unsupported_member_locale_is_ignored(): void
    {
        $member = Member::factory()->make(['locale' => 'fr']);

        $this->assertSame('ja', $this->resolve('member', $member, session: 'ja'));
    }

    public function test_a_guest_falls_back_to_the_session(): void
    {
        $this->assertSame('en', $this->resolve('member', null, session: 'en'));
    }

    public function test_session_scope_ignores_the_member_locale(): void
    {
        // The Filament panel registers SetLocale with the `session` scope so an admin page
        // never picks up a co-logged-in member's persisted locale.
        $member = Member::factory()->make(['locale' => 'en']);

        $this->assertSame('ja', $this->resolve('session', $member, session: 'ja'));
    }

    public function test_post_locale_persists_for_an_authenticated_member(): void
    {
        $member = Member::factory()->create(['locale' => null]);

        $this->actingAs($member, 'member')->post('/locale', ['locale' => 'en']);

        $this->assertSame('en', $member->fresh()->locale);
    }

    public function test_post_locale_ignores_an_unsupported_value(): void
    {
        $member = Member::factory()->create(['locale' => 'ja']);

        $this->actingAs($member, 'member')->post('/locale', ['locale' => 'fr']);

        $this->assertSame('ja', $member->fresh()->locale);
    }

    /** Drive the middleware directly and return the locale it resolves. */
    private function resolve(string $scope, ?Member $member, ?string $session): string
    {
        $request = Request::create('/probe');
        $request->setUserResolver(fn (?string $guard = null) => $guard === 'member' ? $member : null);

        $store = app('session.store');
        $request->setLaravelSession($store);
        if ($session !== null) {
            $store->put('locale', $session);
        }

        (new SetLocale)->handle($request, fn () => response(''), $scope);

        return app()->getLocale();
    }
}

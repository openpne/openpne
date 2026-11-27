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

        $this->assertSame('en', $this->resolve('/probe', $member, session: 'ja'));
    }

    public function test_session_is_used_when_the_member_has_no_locale(): void
    {
        $member = Member::factory()->make(['locale' => null]);

        $this->assertSame('ja', $this->resolve('/probe', $member, session: 'ja'));
    }

    public function test_an_unsupported_member_locale_is_ignored(): void
    {
        $member = Member::factory()->make(['locale' => 'fr']);

        $this->assertSame('ja', $this->resolve('/probe', $member, session: 'ja'));
    }

    public function test_a_guest_falls_back_to_the_session(): void
    {
        $this->assertSame('en', $this->resolve('/probe', null, session: 'en'));
    }

    public function test_the_admin_realm_ignores_the_member_locale(): void
    {
        $member = Member::factory()->make(['locale' => 'en']);

        $this->assertSame('ja', $this->resolve('/admin/members', $member, session: 'ja', sessionKey: SetLocale::ADMIN_SESSION_KEY));
    }

    public function test_the_admin_realm_ignores_the_member_session_toggle(): void
    {
        $this->assertSame('ja', $this->resolve('/admin/members', null, session: 'en', sessionKey: 'locale', accept: 'ja'));
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
    private function resolve(string $path, ?Member $member, ?string $session, string $sessionKey = 'locale', ?string $accept = null): string
    {
        $request = Request::create($path, server: $accept === null ? [] : ['HTTP_ACCEPT_LANGUAGE' => $accept]);
        $request->setUserResolver(fn (?string $guard = null) => $guard === 'member' ? $member : null);

        $store = app('session.store');
        $request->setLaravelSession($store);
        if ($session !== null) {
            $store->put($sessionKey, $session);
        }

        (new SetLocale)->handle($request, fn () => response(''));

        return app()->getLocale();
    }
}

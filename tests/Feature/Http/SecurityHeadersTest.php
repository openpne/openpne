<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_baseline_security_headers_are_present(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'; base-uri 'self'");
    }

    public function test_hsts_is_absent_without_force_https(): void
    {
        // force_https defaults off outside production, so no Strict-Transport-Security in testing.
        $this->assertFalse(config('openpne.security.force_https'));
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_token_screens_send_no_referrer(): void
    {
        $this->get('/login')->assertHeader('Referrer-Policy', 'no-referrer');     // Fortify route
        $this->get('/register')->assertHeader('Referrer-Policy', 'no-referrer');  // registration group
        $this->get('/reset-password/faketoken?email=a@example.com')             // token in the URL
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_non_auth_pages_use_the_softer_referrer_default(): void
    {
        Route::middleware('web')->get('/__sec_probe', fn () => 'ok');

        $this->get('/__sec_probe')->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_spoofed_forwarded_for_is_ignored_without_a_trusted_proxy(): void
    {
        Route::middleware('web')->get('/__sec_ip', fn () => request()->ip());

        // TRUSTED_PROXIES is unset in tests, so the proxy is untrusted and the forwarded header
        // must not move the resolved client IP — the guard against accidentally trusting all proxies.
        $this->get('/__sec_ip', ['X-Forwarded-For' => '203.0.113.9'])
            ->assertOk()
            ->assertSee('127.0.0.1');
    }
}

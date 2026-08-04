<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The administrator-authored message on the Modern sign-in screen. It is rendered server-side through
 * the same Markdown pipeline as a member body (App\Support\MarkdownText), so the client receives
 * already-sanitized HTML and the screen never becomes an operator-HTML seam.
 */
class LoginScreenMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('openpne.surface_mode', 'modern_default');
    }

    public function test_the_message_is_rendered_to_html_for_the_login_screen(): void
    {
        $this->setSnsSetting(SnsSettingKey::LoginMessage, 'Welcome to **our** community.');

        $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/login')
            ->where('loginMessage.body', 'Welcome to **our** community.')
            ->where('loginMessage.bodyHtml', fn ($html) => is_string($html) && str_contains($html, '<strong>our</strong>')));
    }

    public function test_raw_html_in_the_message_is_escaped_not_emitted(): void
    {
        // CommonMark escapes raw HTML (html_input=escape) rather than dropping it, so the operator
        // sees what they typed as text — and no <script> element ever reaches the page.
        $this->setSnsSetting(SnsSettingKey::LoginMessage, '<script>alert(1)</script>');

        $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/login')
            ->where('loginMessage.bodyHtml', fn ($html) => is_string($html)
                && str_contains($html, '&lt;script')
                && ! str_contains($html, '<script')));
    }

    public function test_an_unsafe_link_scheme_never_becomes_an_href(): void
    {
        $this->setSnsSetting(SnsSettingKey::LoginMessage, '[x](javascript:alert(1))');

        $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/login')
            ->where('loginMessage.bodyHtml', fn ($html) => is_string($html) && ! str_contains($html, 'href="javascript:')));
    }

    public function test_no_message_leaves_the_prop_null(): void
    {
        $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/login')
            ->where('loginMessage', null));
    }

    public function test_a_blank_message_leaves_the_prop_null(): void
    {
        // The value is stored verbatim (no trim), so whitespace-only is what an administrator who
        // cleared the field leaves behind — it must not render an empty block above the card.
        $this->setSnsSetting(SnsSettingKey::LoginMessage, "  \n ");

        $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/login')
            ->where('loginMessage', null));
    }
}

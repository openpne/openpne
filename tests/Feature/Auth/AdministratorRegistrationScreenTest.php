<?php

namespace Tests\Feature\Auth;

use App\Features\Auth\RegistrationTokenSource;
use App\Models\AdminRoleInvite;
use App\Models\Banner;
use App\Models\Gadget;
use App\Models\RegistrationToken;
use App\Services\GadgetService;
use App\Support\LayoutHook;
use App\Support\LayoutHooks;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every insertion point is filled with a marker that loads from another origin; the plain invite of
 * another address renders them all, so an empty screen is the withholding and not an empty fixture.
 */
class AdministratorRegistrationScreenTest extends TestCase
{
    use RefreshDatabase;

    private const MARK = 'insertion.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['openpne.security.operator_raw_html' => true]);
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, 'invite');

        foreach ([SnsSettingKey::PcHtmlHead, SnsSettingKey::PcHtmlTop2, SnsSettingKey::PcHtmlTop, SnsSettingKey::PcHtmlBottom2, SnsSettingKey::PcHtmlBottom, SnsSettingKey::FooterBefore, SnsSettingKey::FooterAfter] as $key) {
            $this->setSnsSetting($key, $this->marker('setting-'.$key->value));
        }
        $this->setSnsSetting(SnsSettingKey::CustomCss, '@import url("https://'.self::MARK.'/custom.css");');

        Gadget::create(['context' => 'sidebanner', 'zone' => 'contents', 'name' => 'sideBanner', 'sort_order' => 0]);
        app(GadgetService::class)->clearCache();
        foreach (['top_before', 'side_before'] as $name) {
            Banner::create(['name' => $name, 'is_use_html' => true, 'html' => $this->marker('banner-'.$name), 'markdown' => '']);
        }

        foreach (LayoutHook::cases() as $hook) {
            app(LayoutHooks::class)->register($hook, fn (): string => $this->marker('hook-'.$hook->value));
        }
    }

    private function marker(string $name): string
    {
        return '<script src="https://'.self::MARK.'/'.$name.'.js"></script>';
    }

    private function link(string $email, RegistrationTokenSource $source): string
    {
        $raw = Str::random(40);
        RegistrationToken::create(['email' => $email, 'token' => hash('sha256', $raw), 'source' => $source, 'created_at' => now()]);

        return $raw;
    }

    private function invite(string $email): void
    {
        AdminRoleInvite::create(['email' => $email, 'expires_at' => now()->addDay()->getTimestamp(), 'created_at' => now()]);
    }

    /** @return list<string> every host the HTML loads from or links to other than the site */
    private function foreignHosts(string $html): array
    {
        preg_match_all('#(?:src|href|action)=\\\\?(?:"|\'|&quot;)(?:https?:)?(?:\\\\?/){2}([^/"\'&\\\\]+)#i', $html, $matches);
        $site = parse_url((string) config('app.url'), PHP_URL_HOST);

        return array_values(array_unique(array_filter($matches[1], fn (string $host): bool => $host !== $site)));
    }

    public function test_classic_draws_no_insertion_for_an_administrator_invite(): void
    {
        $plain = $this->get('/register/'.$this->link('member@example.com', RegistrationTokenSource::AdminInvite))->assertOk()->getContent();
        foreach (['setting-pc_html_head', 'setting-pc_html_top2', 'setting-pc_html_top', 'setting-pc_html_bottom2', 'setting-pc_html_bottom', 'setting-footer_before', 'banner-top_before', 'banner-side_before', ...array_map(fn (LayoutHook $hook): string => 'hook-'.$hook->value, [LayoutHook::ClassicHead, LayoutHook::ClassicHeaderEnd, LayoutHook::ClassicLayoutStart, LayoutHook::ClassicCenterEnd, LayoutHook::ClassicLayoutEnd, LayoutHook::ClassicSideBannerEnd, LayoutHook::ClassicFooterEnd, LayoutHook::ClassicBodyEnd])] as $name) {
            $this->assertStringContainsString('/'.$name.'.js', $plain, $name);
        }
        $this->assertStringContainsString('customizing.css', $plain);
        $this->assertSame([self::MARK], $this->foreignHosts($plain));

        $this->invite('admin@example.com');
        $html = $this->get('/register/'.$this->link('admin@example.com', RegistrationTokenSource::AdminRoleInvite))->assertOk()->getContent();

        $this->assertStringNotContainsString(self::MARK, $html);
        $this->assertStringNotContainsString('customizing.css', $html);
        $this->assertStringNotContainsString('id="sideBanner"', $html);
        $this->assertSame([], $this->foreignHosts($html));
        $this->assertStringContainsString('autocomplete="username"', $html);
    }

    public function test_a_replaced_ordinary_link_to_an_invited_address_draws_none_either(): void
    {
        $this->invite('admin@example.com');

        $html = $this->get('/register/'.$this->link('admin@example.com', RegistrationTokenSource::MemberInvite))->assertOk()->getContent();

        $this->assertStringNotContainsString(self::MARK, $html);
        $this->assertSame([], $this->foreignHosts($html));
    }

    public function test_modern_draws_no_insertion_for_an_administrator_invite(): void
    {
        config()->set('openpne.surface_mode', 'modern_default');

        $plain = $this->get('/register/'.$this->link('member@example.com', RegistrationTokenSource::AdminInvite))->assertOk()->getContent();
        foreach ([LayoutHook::ModernHead, LayoutHook::ModernBodyEnd, LayoutHook::ModernAuthStart, LayoutHook::ModernAuthEnd] as $hook) {
            $this->assertStringContainsString('hook-'.$hook->value.'.js', $plain, $hook->value);
        }
        $this->assertSame([self::MARK], $this->foreignHosts($plain));

        $this->invite('admin@example.com');
        $response = $this->get('/register/'.$this->link('admin@example.com', RegistrationTokenSource::AdminRoleInvite))->assertOk();

        $this->assertStringNotContainsString(self::MARK, $response->getContent());
        $this->assertSame([], $this->foreignHosts($response->getContent()));
        $response->assertInertia(fn ($page) => $page->component('auth/register-complete')->where('layoutHooks', []));
    }

    public function test_the_paused_screen_of_an_invited_address_draws_none_either(): void
    {
        $this->setSnsSetting(SnsSettingKey::MemberCapacity, 0);
        $this->invite('admin@example.com');

        $html = $this->get('/register/'.$this->link('admin@example.com', RegistrationTokenSource::AdminRoleInvite))->assertOk()->getContent();

        $this->assertStringNotContainsString(self::MARK, $html);
    }

    public function test_the_screen_is_not_stored_but_stays_the_previous_url(): void
    {
        $raw = $this->link('member@example.com', RegistrationTokenSource::AdminInvite);

        $this->get("/register/{$raw}")->assertOk()->assertHeader('Cache-Control', 'no-store, no-transform, private');

        // A refused submission returns to the form: the session recorded it, and no Referer could lead back.
        $this->post("/register/{$raw}", ['name' => 'Newcomer', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertRedirect("/register/{$raw}");
    }
}

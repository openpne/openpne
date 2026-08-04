<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Filament\Pages\Auth\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin realm identifies itself: the brand name carries the admin-panel suffix (rendered
 * in the header and in every browser title) and the login page announces itself as the
 * administrator login. The heading and the title are pinned separately — the title comes from
 * getTitle() + brand name, not from the heading — and the MFA challenge must keep the vendor
 * heading the override would otherwise mask.
 */
class AdminIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_brand_name_carries_the_admin_suffix(): void
    {
        $this->assertSame(sns_name().' '.__('Admin panel'), Filament::getBrandName());
    }

    public function test_login_browser_title_carries_the_admin_brand(): void
    {
        $html = $this->get('/admin/login')->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<title>(.*?)<\/title>/s', $html, $title));
        $this->assertStringContainsString(sns_name(), $title[1]);
        $this->assertStringContainsString(__('Admin panel'), $title[1]);
    }

    public function test_login_heading_names_the_administrator_realm(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee(__('Administrator login'));
    }

    public function test_mfa_challenge_keeps_the_vendor_heading(): void
    {
        // Direct assignment: the property is #[Locked], which guards client payloads,
        // not server-side state — getHeading() only checks that it is filled.
        $page = new Login;
        $page->userUndertakingMultiFactorAuthentication = encrypt('1');

        $this->assertSame(
            __('filament-panels::auth/pages/login.multi_factor.heading'),
            $page->getHeading(),
        );
    }
}

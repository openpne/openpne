<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Member;
use App\Support\LayoutHook;
use App\Support\LayoutHooks;
use App\Support\SnsSettingKey;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\HtmlString;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\TestCase;

class LayoutHooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(NavigationSeeder::class);
    }

    private function registerMarkers(): void
    {
        $hooks = app(LayoutHooks::class);
        foreach (LayoutHook::cases() as $hook) {
            $hooks->register($hook, fn (): string => '<i data-probe="'.$hook->value.'"></i>');
        }
    }

    private function marker(LayoutHook $hook): string
    {
        return '<i data-probe="'.$hook->value.'"></i>';
    }

    /** Asserts that $needle occurs after $before and before $after, which bound the position. */
    private function assertBetween(string $html, string $before, string $needle, string $after): void
    {
        $start = strpos($html, $before);
        $this->assertNotFalse($start, "landmark {$before} missing");
        $position = strpos($html, $needle, $start);
        $this->assertNotFalse($position, "{$needle} is not after {$before}");
        $end = strpos($html, $after, $start);
        $this->assertNotFalse($end, "landmark {$after} missing");
        $this->assertLessThan($end, $position, "{$needle} is not before {$after}");
    }

    public function test_nothing_registered_renders_no_hook_markup(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-probe', $html);
        $this->assertStringNotContainsString('<div id="sideBanner">', $html);
    }

    public function test_classic_positions_on_a_member_page_with_a_sidemenu(): void
    {
        $this->registerMarkers();
        $member = Member::factory()->create();

        $html = $this->actingAs($member)->get('/block/list')->assertOk()->getContent();

        $this->assertBetween($html, '<head>', $this->marker(LayoutHook::ClassicHead), '</head>');
        $this->assertBetween($html, '<div id="HeaderContainer">', $this->marker(LayoutHook::ClassicHeaderEnd), '</div><!-- HeaderContainer -->');
        $this->assertBetween($html, 'class="Layout">', $this->marker(LayoutHook::ClassicLayoutStart), '<div id="Center">');
        $this->assertBetween($html, '<div id="Left">', $this->marker(LayoutHook::ClassicLeftEnd), '</div><!-- Left -->');
        $this->assertBetween($html, '<div id="Center">', $this->marker(LayoutHook::ClassicCenterEnd), '</div><!-- Center -->');
        $this->assertBetween($html, '</div><!-- Center -->', $this->marker(LayoutHook::ClassicLayoutEnd), '</div><!-- Layout -->');
        $this->assertBetween($html, '<div id="sideBanner">', $this->marker(LayoutHook::ClassicSideBannerEnd), '</div><!-- sideBanner -->');
        $this->assertBetween($html, '<div id="FooterContainer">', $this->marker(LayoutHook::ClassicFooterEnd), '</div><!-- Footer -->');
        $this->assertBetween($html, '</div><!-- Body -->', $this->marker(LayoutHook::ClassicBodyEnd), '</body>');
        $this->assertStringNotContainsString('modern.', $html);
    }

    public function test_classic_header_end_renders_inside_the_theme_navbar_too(): void
    {
        $this->registerMarkers();
        $this->setSnsSetting(SnsSettingKey::ClassicTheme, 'cerulean');
        $member = Member::factory()->create();

        $html = $this->actingAs($member)->get('/')->assertOk()->getContent();

        $this->assertBetween($html, '<div id="HeaderContainer" class="navbar-inner">', $this->marker(LayoutHook::ClassicHeaderEnd), '</div><!-- HeaderContainer -->');
        $this->assertSame(1, substr_count($html, $this->marker(LayoutHook::ClassicHeaderEnd)));
    }

    public function test_the_side_banner_column_stands_for_the_hook_alone(): void
    {
        app(LayoutHooks::class)->register(LayoutHook::ClassicSideBannerEnd, fn (): HtmlString => new HtmlString('<b data-probe="side"></b>'));

        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~<div id="sideBanner">\s*<b data-probe="side"></b>\s*</div><!-- sideBanner -->~', $html);
    }

    public function test_renderers_run_in_order_and_an_empty_one_leaves_nothing(): void
    {
        $hooks = app(LayoutHooks::class);
        $hooks->register(LayoutHook::ClassicHead, fn (): string => '<i data-probe="1"></i>');
        $hooks->register(LayoutHook::ClassicHead, fn (): null => null);
        $hooks->register(LayoutHook::ClassicHead, fn (): string => '<i data-probe="2"></i>');

        $this->assertSame('<i data-probe="1"></i><i data-probe="2"></i>', $hooks->render(LayoutHook::ClassicHead));
        $this->assertSame('', $hooks->render(LayoutHook::ClassicFooterEnd));
    }

    public function test_a_throwing_renderer_is_reported_and_the_classic_not_found_page_still_renders(): void
    {
        Exceptions::fake();
        $hooks = app(LayoutHooks::class);
        $hooks->register(LayoutHook::ClassicHead, function (): string {
            throw new RuntimeException('plan table missing');
        });
        $hooks->register(LayoutHook::ClassicHead, fn (): string => '<i data-probe="after"></i>');
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/diary/999999')->assertNotFound();

        $response->assertSee('<i data-probe="after"></i>', false);
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_modern_blade_positions_render_and_the_client_map_carries_the_rest(): void
    {
        config()->set('openpne.surface_mode', 'modern_only');
        $this->registerMarkers();
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->get('/')->assertOk();
        $html = $response->getContent();

        $this->assertBetween($html, '<head>', $this->marker(LayoutHook::ModernHead), '</head>');
        $this->assertBetween($html, '<body', $this->marker(LayoutHook::ModernBodyEnd), '</body>');
        $this->assertStringNotContainsString('classic.', $html);

        $expected = [];
        foreach (LayoutHook::cases() as $hook) {
            if ($hook->isClientRendered()) {
                $expected[$hook->value] = $this->marker($hook);
            }
        }
        $response->assertInertia(fn (AssertableInertia $page) => $page->where('layoutHooks', $expected));
        $this->assertArrayNotHasKey('modern.head', $expected);
        $this->assertArrayNotHasKey('modern.body_end', $expected);
    }

    public function test_the_client_map_is_the_same_for_a_guest_and_empty_without_registrations(): void
    {
        config()->set('openpne.surface_mode', 'modern_only');

        $this->get('/login')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('layoutHooks', []));

        app(LayoutHooks::class)->register(LayoutHook::ModernAuthEnd, fn (): string => '<i data-probe="auth"></i>');

        $this->get('/login')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('layoutHooks', ['modern.auth_end' => '<i data-probe="auth"></i>']));
    }
}

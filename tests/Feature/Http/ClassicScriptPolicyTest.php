<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Member;
use App\Support\LayoutHook;
use App\Support\LayoutHooks;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ClassicScriptPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const BASELINE = "frame-ancestors 'none'; base-uri 'self'";

    protected function setUp(): void
    {
        parent::setUp();

        config(['openpne.surface_mode' => 'classic_only']);
    }

    /** assertHeader reads only the first of the two Content-Security-Policy headers. */
    private function scriptPolicy(TestResponse $response): ?string
    {
        $policies = $response->headers->all('content-security-policy');
        $this->assertSame(self::BASELINE, $policies[0] ?? null);

        return $policies[1] ?? null;
    }

    private function nonceOf(TestResponse $response): string
    {
        $policy = (string) $this->scriptPolicy($response);
        $this->assertMatchesRegularExpression(
            "~^script-src 'nonce-([A-Za-z0-9]{40})' 'strict-dynamic'; object-src 'none'; worker-src blob:; form-action 'self'$~",
            $policy,
        );

        return (string) preg_replace("~^script-src 'nonce-([A-Za-z0-9]+)'.*$~", '$1', $policy);
    }

    public function test_the_default_install_sends_no_script_policy_and_no_nonce(): void
    {
        $response = $this->actingAs(Member::factory()->create())->get('/timeline')->assertOk();

        $this->assertNull($this->scriptPolicy($response));
        $this->assertStringContainsString('<script src=', (string) $response->getContent());
        $this->assertStringNotContainsString('nonce=', (string) $response->getContent());
    }

    public function test_every_script_the_shell_writes_carries_the_nonce_and_the_operators_does_not(): void
    {
        config(['openpne.security.classic_script_policy' => true]);
        $this->setSnsSetting(SnsSettingKey::PcHtmlHead, '<script id="operator-inline">window.x=1</script>');
        $this->setSnsSetting(SnsSettingKey::PcHtmlBottom, '<script id="operator-src" src="https://operator.example/x.js"></script>');

        $shellScripts = 0;
        foreach ([$this->get('/login'), $this->actingAs(Member::factory()->create())->get('/timeline')] as $response) {
            $nonce = $this->nonceOf($response->assertOk());
            $html = (string) $response->getContent();

            preg_match_all('~<script\b[^>]*>~', $html, $tags);
            $executable = array_values(array_filter($tags[0], fn (string $tag): bool => ! str_contains($tag, 'type="application/json"')));
            $operator = array_values(array_filter($executable, fn (string $tag): bool => str_contains($tag, 'id="operator-')));
            $shell = array_values(array_diff($executable, $operator));

            $this->assertCount(2, $operator);
            foreach ($shell as $tag) {
                $this->assertStringContainsString('nonce="'.$nonce.'"', $tag);
            }
            foreach ($operator as $tag) {
                $this->assertStringNotContainsString('nonce', $tag);
            }
            $shellScripts += count($shell);
        }
        $this->assertGreaterThan(3, $shellScripts);
    }

    public function test_a_layout_hook_reads_the_nonce_the_header_names(): void
    {
        config(['openpne.security.classic_script_policy' => true]);
        app(LayoutHooks::class)->register(LayoutHook::ClassicBodyEnd, fn (): string => '<script id="hooked" nonce="'.Vite::cspNonce().'"></script>');

        $response = $this->get('/login')->assertOk();

        $response->assertSee('<script id="hooked" nonce="'.$this->nonceOf($response).'"></script>', false);
    }

    public function test_error_pages_carry_the_policy(): void
    {
        config(['openpne.security.classic_script_policy' => true]);
        Route::middleware('web')->get('/__forbidden', fn () => abort(403));

        $this->nonceOf($this->get('/no-such-url')->assertNotFound());
        $this->nonceOf($this->get('/__forbidden')->assertForbidden());
    }

    public function test_each_request_gets_its_own_nonce(): void
    {
        config(['openpne.security.classic_script_policy' => true]);

        $this->assertNotSame($this->nonceOf($this->get('/login')), $this->nonceOf($this->get('/login')));
    }

    public function test_the_modern_shell_and_the_admin_panel_are_left_alone(): void
    {
        config(['openpne.security.classic_script_policy' => true, 'openpne.surface_mode' => 'modern_only']);

        $modern = $this->get('/login')->assertOk();
        $this->assertStringContainsString('data-page', (string) $modern->getContent());
        $this->assertNull($this->scriptPolicy($modern));
        $this->assertNull($this->scriptPolicy($this->get('/admin/login')->assertOk()));

        // The Classic 404 of an unmatched URL, after a Modern render in the same process.
        $this->nonceOf($this->get('/no-such-url')->assertNotFound());
    }

    public function test_a_classic_error_page_that_replaces_a_modern_render_carries_the_policy(): void
    {
        config(['openpne.security.classic_script_policy' => true]);
        Route::middleware('web')->get('/__modern_then_fail', function () {
            view('app', ['page' => []])->render();
            abort(404);
        });

        $this->nonceOf($this->get('/__modern_then_fail')->assertNotFound());
    }
}

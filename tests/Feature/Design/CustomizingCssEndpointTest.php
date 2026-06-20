<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin custom CSS document, served as text/css the way OpenPNE 3 did (a <link>ed stylesheet, not
 * an inline <style>): public, dynamic from the DB, with a content ETag for conditional GETs.
 */
class CustomizingCssEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_serves_stored_css_as_text_css(): void
    {
        $this->setSnsSetting(SnsSettingKey::CustomCss, '#logo { color: red; }');

        $response = $this->get('/cache/css/customizing.css');

        $response->assertOk();
        $this->assertSame('#logo { color: red; }', $response->getContent());
        $this->assertStringContainsString('text/css', (string) $response->headers->get('Content-Type'));
        $this->assertNotNull($response->headers->get('ETag'));
        $this->assertStringContainsString('max-age', (string) $response->headers->get('Cache-Control'));
    }

    public function test_is_reachable_by_a_guest(): void
    {
        $this->setSnsSetting(SnsSettingKey::CustomCss, 'body{}');

        $this->get('/cache/css/customizing.css')->assertOk();
    }

    public function test_returns_empty_body_when_unset(): void
    {
        $response = $this->get('/cache/css/customizing.css');

        $response->assertOk();
        $this->assertSame('', $response->getContent());
    }

    public function test_returns_304_when_the_etag_matches(): void
    {
        $this->setSnsSetting(SnsSettingKey::CustomCss, '#logo { color: red; }');

        $etag = (string) $this->get('/cache/css/customizing.css')->headers->get('ETag');

        $this->get('/cache/css/customizing.css', ['If-None-Match' => $etag])
            ->assertStatus(304);
    }
}

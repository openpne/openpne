<?php

namespace Tests\Feature\Surface;

use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use App\Support\SurfaceMode;
use App\Support\SurfaceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * A fresh OpenPNE 4 install defaults to modern_only. The suite pins OPENPNE_SURFACE_MODE=classic_default
 * (phpunit.xml) so the legacy Classic tests keep their assumption — which masks the shipped default — so
 * it is asserted by re-reading the config file with that env override cleared, and the resolver contract
 * is asserted with the config set explicitly.
 */
class InstallDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipped_config_default_is_modern_only(): void
    {
        $key = 'OPENPNE_SURFACE_MODE';
        $prev = ['env' => $_ENV[$key] ?? null, 'server' => $_SERVER[$key] ?? null, 'getenv' => getenv($key)];
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);

        try {
            // Re-read the file directly (not config()) so env() re-evaluates against the cleared override.
            $config = require base_path('config/openpne.php');
            $this->assertSame('modern_only', $config['surface_mode']);
        } finally {
            if ($prev['getenv'] !== false) {
                putenv("{$key}={$prev['getenv']}");
            }
            if ($prev['env'] !== null) {
                $_ENV[$key] = $prev['env'];
            }
            if ($prev['server'] !== null) {
                $_SERVER[$key] = $prev['server'];
            }
        }
    }

    public function test_resolver_serves_modern_under_modern_only_with_no_row_or_preference(): void
    {
        config(['openpne.surface_mode' => 'modern_only']);

        $this->assertFalse(SurfaceResolver::classicAvailable());
        $this->assertSame(SurfaceMode::ModernOnly, app(SnsSettingService::class)->get(SnsSettingKey::SurfaceMode));

        // modern_only is a hard gate above member preference / session, so a canonical route resolves to
        // Modern for a guest request with no member and no override.
        $this->assertSame(SurfaceResolver::MODERN, SurfaceResolver::canonicalSurface(Request::create('/diary/list'), 'diary'));
    }
}

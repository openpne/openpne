<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\Http\Controllers\AppIconController;
use App\Http\Controllers\BannerImageController;
use App\Http\Controllers\CustomizingCssController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\LinkCardImageController;
use App\Http\Controllers\PublicFileController;
use App\Support\AdminRealm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The client leaves a link under BYTES_SEGMENTS to the browser instead of the router, so the list
 * must be exactly the first segments the byte-serving controllers answer under (docs/internals/body-text.md,
 * "Where a link opens").
 */
class BytesRouteSegmentsParityTest extends TestCase
{
    /** The controllers that answer with bytes rather than a page, outside the admin realm. */
    private const BYTES_CONTROLLERS = [
        AppIconController::class,
        BannerImageController::class,
        CustomizingCssController::class,
        FileController::class,
        ImageController::class,
        LinkCardImageController::class,
        PublicFileController::class,
    ];

    public function test_the_client_lists_exactly_the_segments_the_byte_routes_answer_under(): void
    {
        $expected = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (AdminRealm::matches(Request::create('/'.ltrim(preg_replace('/\{[^}]+\}/', 'x', $uri), '/')))) {
                continue;
            }
            $controller = $route->getControllerClass();
            if ($controller !== null && in_array($controller, self::BYTES_CONTROLLERS, true)) {
                $expected[] = explode('/', $uri)[0];
            }
        }

        $expected = array_values(array_unique($expected));
        sort($expected);

        $actual = $this->clientSegments();
        sort($actual);

        $this->assertSame($expected, $actual,
            'BYTES_SEGMENTS in resources/js/lib/link-target.ts and the byte-serving routes disagree: a segment the client leaves to the browser must answer with bytes, and every byte route needs its segment listed.');
    }

    /** The string literals of the BYTES_SEGMENTS array. @return list<string> */
    private function clientSegments(): array
    {
        $source = (string) file_get_contents(resource_path('js/lib/link-target.ts'));

        $this->assertMatchesRegularExpression('/export const BYTES_SEGMENTS = \[(?<body>[^\]]*)\] as const/', $source);
        preg_match('/export const BYTES_SEGMENTS = \[(?<body>[^\]]*)\] as const/', $source, $m);
        preg_match_all("/'([^']+)'/", $m['body'], $literals);

        return $literals[1];
    }
}

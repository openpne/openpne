<?php

declare(strict_types=1);

namespace App\Support;

use Inertia\Response;
use Inertia\ResponseFactory;

/**
 * The one place every Modern page is built, so a Modern-only screen (one with no Classic twin) answers
 * 404 under classic_only without each route naming the gate (docs/internals/feature-modules.md, "Surface selection").
 */
class ClassicOnlyResponseFactory extends ResponseFactory
{
    public function render($component, $props = []): Response
    {
        abort_unless(SurfaceResolver::modernAvailable(), 404);

        return parent::render($component, $props);
    }
}

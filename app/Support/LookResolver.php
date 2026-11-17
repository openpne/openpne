<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/** A member and a guest get the same layout (docs/internals/looks.md, "Resolution"). */
final class LookResolver
{
    public static function resolve(Request $request): Look
    {
        return Look::Tabbed;
    }
}

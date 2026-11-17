<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Member;
use Illuminate\Http\Request;

/**
 * Every member gets the one layout, and a guest still gets the standard frame because the pages a
 * look swaps need a viewer to render against (docs/internals/looks.md, "Resolution").
 */
final class LookResolver
{
    public static function resolve(Request $request): Look
    {
        return $request->user('member') instanceof Member ? Look::Tabbed : Look::Standard;
    }
}

<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Support\Feature;
use Laravel\Mcp\Request;

abstract class DiaryTool extends McpTool
{
    protected const REFUSED = 'No such diary — or it is not yours to read.';

    /** Bytes, not characters: the TEXT column's own size, which MySQL enforces at insert time. */
    protected const BODY_MAX_BYTES = 65535;

    public function shouldRegister(): bool
    {
        return Feature::Diary->enabled();
    }

    /**
     * This path meets no TrimStrings middleware, so whitespace-only text is trimmed to empty here.
     * A non-string is left as it came for the `string` rule to refuse rather than coerced.
     */
    protected static function trimmed(Request $request, string $key): mixed
    {
        $value = $request->get($key);

        return is_string($value) ? trim($value) : $value;
    }
}

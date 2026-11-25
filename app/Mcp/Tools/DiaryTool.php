<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Support\Feature;
use Illuminate\Validation\ValidationException;
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
     * Refused rather than ignored, as an entry or comment written without the pictures its caller meant
     * to attach is announced all the same; only absent, null and [] read as "no pictures".
     *
     * @throws ValidationException
     */
    protected static function refuseImages(Request $request): void
    {
        if (! in_array($request->get('images'), [null, []], true)) {
            throw ValidationException::withMessages(['images' => ['This tool does not take pictures: post the text alone.']]);
        }
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

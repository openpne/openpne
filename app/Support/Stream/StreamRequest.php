<?php

namespace App\Support\Stream;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StreamRequest
{
    public const PARAM = 'before';

    /** A malformed cursor reads as the head of the stream; only the MCP realm refuses one (docs/internals/ordering.md, "Keyset and offset"). */
    public static function before(Request $request): ?StreamCursor
    {
        return StreamCursor::tryParse($request->query(self::PARAM));
    }

    /** A bookmarked `?page=N` from before the list became a stream lands on the head; `?page=1` is left alone. */
    public static function legacyPageRedirect(Request $request): ?RedirectResponse
    {
        $page = $request->query('page');
        if ($page === null || $page === '1') {
            return null;
        }

        return redirect()->to($request->fullUrlWithoutQuery('page'));
    }
}

<?php

declare(strict_types=1);

namespace App\Features\GroupTalk;

use App\Http\Requests\Concerns\MentionRules;

/**
 * Deliberately only the cap and the newline rule: the rest of what the browser form applies is
 * middleware a token request never meets.
 */
final class TalkBody
{
    /**
     * Code points, not bytes: `max` measures a string with mb_strlen, the same unit the mention
     * ranges are recorded in and the one JavaScript's Array.from() agrees with. Well inside the TEXT
     * column even at four bytes a point.
     */
    public const MAX = 5000;

    /**
     * The body as the client measured it — LF newlines, so a stored body's line breaks match the
     * offsets its mentions carry and the length check counts what was typed.
     */
    public static function normalize(string $body): string
    {
        return MentionRules::normalizeNewlines($body);
    }
}

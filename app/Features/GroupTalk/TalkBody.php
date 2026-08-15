<?php

declare(strict_types=1);

namespace App\Features\GroupTalk;

use App\Http\Requests\Concerns\MentionRules;

/**
 * What a talk message's text has to be before it is stored, held apart from the browser form so a
 * second wire — the MCP tool — states the same contract instead of a similar one.
 *
 * It is deliberately only the cap and the newline rule. Everything else the form applies (trimming,
 * the `string` check) is middleware a browser request meets on its way in and a token request does
 * not, so each wire declares it where it applies.
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

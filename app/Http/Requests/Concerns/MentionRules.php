<?php

namespace App\Http\Requests\Concerns;

/**
 * A structural violation (a missing key, a negative offset, more rows than the cap) rejects the whole
 * post, because only the compose form's picker produces this payload. A row that merely stopped
 * describing reality (a renamed, blocked or deleted member) is instead dropped one row at a time by
 * ResolveMentions.
 */
final class MentionRules
{
    /**
     * @param  int  $bodyMax  the composing surface's body cap in code points; only the offset and
     *                        length bounds depend on it
     * @return array<string, mixed>
     */
    public static function rules(int $bodyMax = 140): array
    {
        return [
            // More than this cannot fit a body worth reading, and the cap bounds the id lookup
            // ResolveMentions makes over the payload.
            'mentions' => ['sometimes', 'array', 'max:10'],
            'mentions.*.member_id' => ['required', 'integer'],
            // Bounded by the body cap (a mention starts inside the body and is at least "@x" long);
            // whether it fits this body is ResolveMentions' check.
            'mentions.*.offset' => ['required', 'integer', 'min:0', 'max:'.($bodyMax - 1)],
            'mentions.*.length' => ['required', 'integer', 'min:2', 'max:'.$bodyMax],
        ];
    }

    /**
     * The body as the client measured it. A browser submits a textarea's newlines as CRLF, but the
     * picker computes offsets over the DOM value, whose newlines are LF — leaving CRLF in would
     * shift every offset past a newline by one per preceding line break.
     */
    public static function normalizeNewlines(string $body): string
    {
        return strtr($body, ["\r\n" => "\n", "\r" => "\n"]);
    }

    /**
     * The validated payload as ints: `integer` accepts the numeric strings a form POST sends.
     *
     * @param  array<int, array<string, mixed>>  $mentions
     * @return list<array{member_id: int, offset: int, length: int}>
     */
    public static function normalize(array $mentions): array
    {
        return array_values(array_map(fn (array $mention): array => [
            'member_id' => (int) $mention['member_id'],
            'offset' => (int) $mention['offset'],
            'length' => (int) $mention['length'],
        ], $mentions));
    }
}

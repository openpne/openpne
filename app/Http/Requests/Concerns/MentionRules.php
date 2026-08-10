<?php

namespace App\Http\Requests\Concerns;

/**
 * Validation and normalization for the `mentions[]` payload a timeline post or reply carries,
 * shared so both forms bound and read it the same way.
 *
 * Two layers of failure, deliberately split: the payload is only ever produced by the compose
 * form's mention picker, so a *structural* violation (a missing key, a negative offset, more rows
 * than the cap) means a broken client or tampering — the whole post is rejected here. A row that
 * merely stopped describing reality (the member was renamed, blocked, deleted) is dropped one row
 * at a time by App\Features\Timeline\Actions\ResolveMentions, because the member wrote a message,
 * not a mention list.
 */
final class MentionRules
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            // A 140-code-point body has no room for more, and the cap bounds the id lookup
            // ResolveMentions makes over the payload.
            'mentions' => ['sometimes', 'array', 'max:10'],
            'mentions.*.member_id' => ['required', 'integer'],
            // Bounded by the body cap: a mention starts inside a 140-code-point body and is at
            // least "@x" long. Whether it actually fits *this* body is ResolveMentions' check.
            'mentions.*.offset' => ['required', 'integer', 'min:0', 'max:139'],
            'mentions.*.length' => ['required', 'integer', 'min:2', 'max:140'],
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

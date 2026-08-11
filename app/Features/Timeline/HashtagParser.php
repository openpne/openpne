<?php

namespace App\Features\Timeline;

use Normalizer;

/**
 * Reads the #hashtags out of a timeline post's body.
 *
 * The mirror image of a mention: a mention exists because someone picked a member, so the client
 * sends the ranges and the server only re-checks them. Nobody picks a tag — there is no entity to
 * pick — so the body is the only source, and the server parses it at save time. That is also why
 * this can run over bodies nobody composed here (the backfill command, an upgraded OpenPNE 3 post),
 * where no payload exists at all.
 *
 * A tag is stored normalized so a lookup can be an equality test: `#Tag`, `#ＴＡＧ` and `#tag` are
 * one topic. The range stays over the *raw* body, so the text a reader sees is the text that was
 * typed. The normalization below is therefore a stored format, not a display choice — changing it
 * means re-normalizing every existing row (docs/internals/timeline.md).
 */
final class HashtagParser
{
    /** As many as a 140-code-point body can carry before it stops being a sentence. */
    private const MAX_TAGS = 10;

    /** Code points, enforced twice: by the pattern over the raw run, and after NFKC expands it. */
    private const MAX_LENGTH = 30;

    private const TAG_CHAR = '[\p{L}\p{M}\p{N}_]';

    /**
     * A marker at the start of the body or after whitespace, then a tag run.
     *
     * U+FF03 is the marker a Japanese IME produces. The trailing negative lookahead is what makes an
     * over-long run fail *whole* rather than matching its first 30 characters — backtracking cannot
     * find a shorter match either, so a 31-character run yields no tag at all.
     */
    private const PATTERN = '/(?<=^|[\s\p{Z}])([#\x{FF03}])('.self::TAG_CHAR.'{1,'.self::MAX_LENGTH.'})(?!'.self::TAG_CHAR.')/u';

    /**
     * @param  list<array{offset: int, length: int}>  $mentionRanges  the post's stored mentions
     * @return list<array{tag: string, offset: int, length: int}> ascending by offset
     */
    public static function parse(string $body, array $mentionRanges = []): array
    {
        if (! preg_match_all(self::PATTERN, $body, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }

        $tags = [];
        $byteCursor = 0;
        $pointCursor = 0;

        foreach ($matches as $match) {
            // preg counts bytes, ranges count code points. Measuring only the gap since the previous
            // match keeps the walk linear instead of re-measuring the body's head every time.
            $pointCursor += mb_strlen(substr($body, $byteCursor, $match[1][1] - $byteCursor));
            $byteCursor = $match[1][1];

            $raw = $match[2][0];
            $offset = $pointCursor;
            $length = 1 + mb_strlen($raw);

            if (self::insideMention($offset, $length, $mentionRanges)) {
                continue;
            }

            $tag = self::normalize($raw);

            // A run of digits is a number someone wrote, not a topic. NFKC can also turn one
            // character into several (`Ⅷ` into `viii`), overrunning the cap the pattern enforced.
            if (preg_match('/^\p{N}+$/u', $tag) === 1 || mb_strlen($tag) > self::MAX_LENGTH) {
                continue;
            }

            $tags[] = ['tag' => $tag, 'offset' => $offset, 'length' => $length];

            if (count($tags) === self::MAX_TAGS) {
                break;
            }
        }

        return $tags;
    }

    /**
     * The stored form of a tag, and therefore the only form a lookup may compare against: the column
     * is byte-equal on every engine, so a query that skips this finds nothing for `#Tag` or `#ＴＡＧ`.
     */
    public static function normalize(string $tag): string
    {
        // Normalizer::normalize() only fails on invalid UTF-8, which the pattern's /u already refused
        // on the write side; a lookup term reaching here malformed keeps its raw form and matches nothing.
        return mb_strtolower(Normalizer::normalize($tag, Normalizer::FORM_KC) ?: $tag);
    }

    /**
     * Whether the candidate overlaps a mention. The mention wins: its range is a member's deliberate
     * choice, so a `#` inside a display name is part of that name, not a topic.
     *
     * @param  list<array{offset: int, length: int}>  $mentionRanges
     */
    private static function insideMention(int $offset, int $length, array $mentionRanges): bool
    {
        foreach ($mentionRanges as $mention) {
            if ($offset < $mention['offset'] + $mention['length'] && $mention['offset'] < $offset + $length) {
                return true;
            }
        }

        return false;
    }
}

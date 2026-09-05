<?php

namespace App\Features\Timeline;

use Normalizer;

/**
 * Nothing picks a tag, so the body is the only source and the server parses it at save time — over
 * bodies nobody composed here too (the backfill, an upgraded post). Normalization is therefore a
 * stored format rather than a display choice (docs/internals/timeline.md, "The stored tag is
 * normalized; the range is not").
 */
final class HashtagParser
{
    /** As many as a 140-code-point body can carry before it stops being a sentence. */
    private const MAX_TAGS = 10;

    /** Code points, enforced twice: by the pattern over the raw run, and after NFKC expands it. */
    private const MAX_LENGTH = 30;

    private const TAG_CHAR = '[\p{L}\p{M}\p{N}_]';

    /**
     * A marker at the start of the body or after whitespace, then a tag run; U+FF03 is the marker a
     * Japanese IME produces. The trailing negative lookahead makes an over-long run fail *whole* —
     * backtracking cannot find a shorter match either — rather than match its first 30 characters.
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
            // preg counts bytes and ranges count code points, so only the gap since the previous
            // match is measured — re-measuring the head each time would make the walk quadratic.
            $pointCursor += mb_strlen(substr($body, $byteCursor, $match[1][1] - $byteCursor));
            $byteCursor = $match[1][1];

            $raw = $match[2][0];
            $offset = $pointCursor;
            $length = 1 + mb_strlen($raw);

            if (self::insideMention($offset, $length, $mentionRanges)) {
                continue;
            }

            $tag = self::normalize($raw);

            // A digit run is a number someone wrote rather than a topic, and NFKC can turn one
            // character into several (`Ⅷ` into `viii`) past the cap the pattern enforced.
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

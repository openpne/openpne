<?php

declare(strict_types=1);

namespace App\LinkCard;

/**
 * Reads a normalised URL as one of three answers: somewhere else, here-and-we-know-what, or
 * here-but-nothing-we-can-draw.
 *
 * Purely textual, and deliberately so — it is asked both when a body is parsed and again when a card
 * is drawn ({@see LinkCardSerializer}), and the second answer has to be derivable from the URL alone.
 *
 * **Being ours is decided before being resolvable, and settles the question of fetching on its own.**
 * A URL on this host is never handed to the fetcher, whether or not it names something a card can be
 * built from. The alternative — treating what we cannot resolve as an external link — leaves this
 * app requesting its own pages for every address outside the canonical set: the OpenPNE 3-compatible
 * spellings (whose redirects only help a reader), list pages, and anything added to the routing
 * table later. On a deployment behind a private address `PublicIpGuard` refuses those requests, so
 * they would also be a permanent supply of failed rows serving out their backoff.
 *
 * Host and port only, never the scheme: `http://` and `https://` of this site are the same server,
 * and requesting one of them because the other is configured is the self-fetch this exists to stop.
 *
 * A port outside {@see LinkUrl}'s 80/443 never arrives here at all — `normalize()` has already
 * refused it, and the body's next URL takes its place. That restriction is not relaxed for our own
 * host: it exists so no row is minted for an address the fetcher would refuse, and widening it here
 * would leave the external half of that rule stated in two places. A site served on another port
 * therefore draws no internal cards, which is a deployment outside the fleet standard.
 */
final class InternalUrl
{
    /** Digits accepted as a record id — 18 keeps `(int)` from saturating at PHP_INT_MAX. */
    private const MAX_ID_DIGITS = 18;

    private function __construct(
        /** Whether the URL is served by this site. */
        public bool $isSelfHosted,
        /** The kind of record it names, or null when nothing here names one. */
        public ?InternalCardTarget $target,
        /** That record's id, null exactly when $target is. */
        public ?int $recordId,
        /** The group the URL's path names, for the one kind whose path carries one (talk). */
        public ?int $groupId = null,
    ) {}

    /** How $normalizedUrl relates to this site. Takes the output of {@see LinkUrl::normalize()}. */
    public static function of(string $normalizedUrl): self
    {
        $base = self::selfBase();
        $parts = parse_url($normalizedUrl);

        if ($base === null || $parts === false || ! isset($parts['host'])) {
            return new self(false, null, null);
        }

        $authority = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if ($authority !== $base['authority']) {
            return new self(false, null, null);
        }

        $path = $parts['path'] ?? '';

        // An app served from a sub-directory prefixes every route with it, and a URL that does not
        // carry the prefix is not one of this app's pages even on this host.
        if ($base['path'] !== '' && ! str_starts_with($path, $base['path'].'/') && $path !== $base['path']) {
            return new self(true, null, null);
        }

        $segments = self::segments(substr($path, strlen($base['path'])));
        parse_str($parts['query'] ?? '', $query);

        [$target, $id, $groupId] = self::route($segments, $query);

        return new self(true, $target, $id, $groupId);
    }

    /**
     * The canonical route $segments names, as a kind and an id.
     *
     * A closed list, matched on the whole path rather than on a prefix: the URL picks which of seven
     * models is loaded, so anything it does not name exactly resolves to nothing. That is also what
     * keeps `/timeline/new` and `/groups/mine` — sibling routes whose segment is a word rather than
     * an id — out of the resolved set without naming them here.
     *
     * The OpenPNE 3-compatible spellings are deliberately absent. They redirect, which serves a
     * reader following the link and does nothing for a card; resolving them would also mean carrying
     * a second address table that has to stay in step with the first.
     *
     * @param  list<string>  $segments
     * @param  array<string, mixed>  $query
     * @return array{InternalCardTarget|null, int|null, int|null}
     */
    private static function route(array $segments, array $query): array
    {
        $id = self::id($segments[1] ?? null);
        $kind = match (true) {
            count($segments) !== 2 => null,
            $segments[0] === 'diary' => InternalCardTarget::Diary,
            $segments[0] === 'topics' => InternalCardTarget::Topic,
            $segments[0] === 'events' => InternalCardTarget::Event,
            $segments[0] === 'timeline' => InternalCardTarget::TimelinePost,
            $segments[0] === 'groups' => InternalCardTarget::Group,
            $segments[0] === 'member' => InternalCardTarget::Member,
            default => null,
        };

        if ($kind !== null && $id !== null) {
            return [$kind, $id, null];
        }

        // A conversation is a page rather than a row, so the message is in the query — the deep link
        // the talk surface itself hands out. The group the path names rides along rather than being
        // checked here: the conversation page refuses an anchor naming another room's message
        // (GroupTalkController::anchor scopes its lookup to the route's group), and the render
        // applies the same refusal through {@see InternalCardTarget::urlLeadsTo()}.
        if (count($segments) === 3 && $segments[0] === 'groups' && $segments[2] === 'talk' && ($group = self::id($segments[1])) !== null) {
            $message = self::id(is_string($query['m'] ?? null) ? $query['m'] : null);

            if ($message !== null) {
                return [InternalCardTarget::TalkMessage, $message, $group];
            }
        }

        return [null, null, null];
    }

    /** $segment as a record id, or null when it is not one. */
    private static function id(?string $segment): ?int
    {
        if ($segment === null || $segment === '' || strlen($segment) > self::MAX_ID_DIGITS || ! ctype_digit($segment)) {
            return null;
        }

        return (int) $segment > 0 ? (int) $segment : null;
    }

    /**
     * $path split on `/`, empty parts dropped so a trailing slash names the same page.
     *
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', rawurldecode($path)), fn (string $part): bool => $part !== ''));
    }

    /**
     * This site's authority and route prefix, or null when the configured URL is not one this app
     * would ever store a card for — a non-standard port, most of all, which `normalize()` refuses on
     * both sides so nothing can match it.
     *
     * @return array{authority: string, path: string}|null
     */
    private static function selfBase(): ?array
    {
        $normalized = LinkUrl::normalize((string) config('app.url'));

        if ($normalized === null) {
            return null;
        }

        $parts = parse_url($normalized);

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        return [
            'authority' => $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''),
            'path' => rtrim($parts['path'] ?? '', '/'),
        ];
    }
}

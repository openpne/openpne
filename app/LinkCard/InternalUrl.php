<?php

declare(strict_types=1);

namespace App\LinkCard;

/**
 * Reads a normalised URL as one of three answers, purely textually, because it is asked again when
 * a card is drawn. Being ours is decided by host and port before being resolvable and settles
 * fetching on its own: a URL on this host is never handed to the fetcher
 * (docs/internals/link-cards.md, "Links to this site are never fetched").
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
     * A closed list matched on the whole path, since the URL picks which of seven models is loaded;
     * that is also what keeps `/timeline/new` and `/groups/mine` out of the resolved set without
     * naming them. The OpenPNE 3-compatible spellings are deliberately absent: they redirect, which
     * serves a reader and not a card, and resolving them would mean a second address table.
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

        // A conversation is a page, so the message is in the query, and the path's group rides along
        // unchecked: the render applies the conversation page's own refusal of another room's
        // message through {@see InternalCardTarget::urlLeadsTo()}.
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

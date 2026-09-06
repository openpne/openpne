<?php

namespace App\Support;

/**
 * Where a link opens: another site in a new tab that says so, this site in place. Host and port
 * against app.url decide, never the scheme (docs/internals/body-text.md, "Where a link opens").
 */
final class LinkTarget
{
    public const REL = 'noopener noreferrer nofollow';

    private function __construct(
        /** Whether the link leaves this site. */
        public readonly bool $external,
    ) {}

    public static function of(string $url): self
    {
        $site = self::authority((string) config('app.url'));
        $link = self::authority($url);

        return new self($site === null || $link === null || $site !== $link);
    }

    /** The attributes after href: a new tab with $rel for a link that leaves, nothing for one that stays. */
    public function attributes(string $rel = self::REL): string
    {
        return $this->external ? ' target="_blank" rel="'.e($rel).'"' : '';
    }

    /** The visually hidden notice closing the link's text; empty for a link that stays. */
    public function notice(): string
    {
        return $this->external ? '<span class="sr-only"> '.e(__('Opens in a new tab')).'</span>' : '';
    }

    /**
     * `host` or `host:port` of an http(s) $url, or null for anything else. 80 and 443 are dropped
     * whichever scheme carries them, so that a site's two schemes read as one site; any other port
     * counts, unlike for a card, since no fetch depends on it (docs/internals/link-cards.md, "Links to this site are never fetched").
     */
    private static function authority(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $port = $parts['port'] ?? null;

        return strtolower(rtrim($parts['host'], '.')).($port === null || $port === 80 || $port === 443 ? '' : ':'.$port);
    }
}

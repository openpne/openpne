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
     * `host` or `host:port` as a browser reads it (WHATWG URL: the scheme's default port dropped, an
     * IDN in punycode), or null where parse_url would read a different host than the browser — a
     * backslash or userinfo, which a browser cuts the host at and parse_url does not.
     */
    private static function authority(string $url): ?string
    {
        $url = trim($url);

        if (str_contains($url, '\\')) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $host = strtolower($parts['host']);

        if (preg_match('/[^\x20-\x7e]/', $host) === 1) {
            $host = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

            if ($host === false) {
                return null;
            }
        }

        $port = $parts['port'] ?? null;

        if ($port === ($scheme === 'https' ? 443 : 80)) {
            $port = null;
        }

        return $host.($port === null ? '' : ':'.$port);
    }
}

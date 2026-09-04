<?php

declare(strict_types=1);

namespace App\Outbound;

/**
 * `url` is the URL of the response that produced the body, after redirects. Relative references
 * found in the body resolve against it (RFC 3986); resolving against the requested URL points at the
 * wrong origin whenever a redirect crossed hosts.
 */
final readonly class FetchedResponse
{
    public function __construct(
        public string $url,
        public int $status,
        public string $contentType,
        public string $body,
        public bool $truncated,
    ) {}

    /** The Content-Type without parameters, lowercased ("text/html; charset=euc-jp" -> "text/html"). */
    public function mediaType(): string
    {
        return strtolower(trim(explode(';', $this->contentType, 2)[0]));
    }

    /** The charset parameter of the Content-Type, or null when it carries none. */
    public function charset(): ?string
    {
        if (preg_match('/;\s*charset\s*=\s*"?([^";\s]+)"?/i', $this->contentType, $matches) !== 1) {
            return null;
        }

        return strtolower($matches[1]);
    }
}

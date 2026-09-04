<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Outbound\OutboundException;
use App\Outbound\SafeHttpFetcher;

/**
 * Only the structured fields are used and the `html` field is never touched: it is
 * provider-authored markup, and a card is drawn from text and a self-hosted image, so there is
 * nothing markup could add (docs/internals/link-cards.md, The card is never HTML). The request goes
 * through `SafeHttpFetcher` like any other, since the endpoint URL came out of a stranger's page.
 */
final class OembedClient
{
    /** oEmbed responses are small; anything this size is not one. */
    private const MAX_BYTES = 64 * 1024;

    private const ACCEPTED_TYPES = ['application/json', 'text/json', 'application/json+oembed', 'text/javascript'];

    public function __construct(private readonly SafeHttpFetcher $fetcher) {}

    /**
     * Never throws: an unreachable, hostile or non-oEmbed endpoint leaves the card with whatever the
     * page provided, since failing an optional enrichment would turn a working card into no card.
     *
     * @param  float|null  $deadline  the job's remaining budget, so this cannot spend a fresh one
     */
    public function fetch(string $url, ?float $deadline = null): LinkMetadata
    {
        try {
            $response = $this->fetcher->get($url, self::MAX_BYTES, $deadline);
        } catch (OutboundException) {
            return new LinkMetadata;
        }

        if ($response->status !== 200 || ! $this->isJson($response->mediaType())) {
            return new LinkMetadata;
        }

        $payload = json_decode($response->body, true);

        if (! is_array($payload)) {
            return new LinkMetadata;
        }

        return new LinkMetadata(
            title: $this->text($payload['title'] ?? null),
            siteName: $this->text($payload['provider_name'] ?? null),
            authorName: $this->text($payload['author_name'] ?? null),
            // Resolved against the endpoint's own response URL, since a provider may answer with a
            // relative thumbnail; whether it may be fetched is decided when it is fetched, by the
            // same guard as everything else.
            imageUrl: LinkUrl::resolve($this->text($payload['thumbnail_url'] ?? null), $response->url),
        );
    }

    private function isJson(string $mediaType): bool
    {
        return in_array($mediaType, self::ACCEPTED_TYPES, true);
    }

    /** JSON gives no guarantee of type, so anything that is not a non-empty string is discarded. */
    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $value)));

        return $value === '' ? null : $value;
    }
}

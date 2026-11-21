<?php

declare(strict_types=1);

namespace App\SiteCheck;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Fetches an address the operator gave this site, APP_URL or openpne:check's --parent-url, never one
 * a member typed, which is why it bypasses App\Outbound (docs/internals/outbound-http.md).
 */
final class SiteFetcher
{
    private const TIMEOUT_SECONDS = 10;

    /** @param array<string, string> $headers */
    public function get(string $url, array $headers = []): FetchedPage
    {
        try {
            $response = Http::withoutRedirecting()
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->withHeaders($headers)
                ->get($url);
        } catch (ConnectionException $e) {
            return new FetchedPage(null, '', null, $e->getMessage());
        }

        return new FetchedPage($response->status(), $response->body(), $response->header('Location') ?: null);
    }
}

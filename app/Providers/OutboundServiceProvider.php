<?php

declare(strict_types=1);

namespace App\Providers;

use App\Outbound\CurlClientFactory;
use App\Outbound\DnsHostResolver;
use App\Outbound\HostResolver;
use App\Outbound\PublicIpGuard;
use App\Outbound\SafeHttpFetcher;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the outbound HTTP seam. See App\Outbound\SafeHttpFetcher and docs/internals/outbound-http.md.
 */
class OutboundServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(HostResolver::class, DnsHostResolver::class);

        $this->app->bind(PublicIpGuard::class, fn (): PublicIpGuard => new PublicIpGuard(
            array_values((array) config('openpne.outbound.denied_cidrs', [])),
        ));

        $this->app->bind(SafeHttpFetcher::class, function (): SafeHttpFetcher {
            return new SafeHttpFetcher(
                client: (new CurlClientFactory)->make(),
                resolver: $this->app->make(HostResolver::class),
                guard: $this->app->make(PublicIpGuard::class),
                userAgent: $this->userAgent(),
                connectTimeout: $this->positive('connect_timeout', 3),
                requestTimeout: $this->positive('request_timeout', 8),
                fetchTimeout: $this->positive('fetch_timeout', 10),
                maxRedirects: max(0, (int) config('openpne.outbound.max_redirects')),
            );
        });
    }

    /**
     * Zero means no limit to Guzzle and libcurl (docs/internals/outbound-http.md). The default rather
     * than a bare 1 second, so a mistyped value behaves like an unset one.
     */
    private function positive(string $key, int $fallback): int
    {
        $value = (int) config("openpne.outbound.{$key}");

        return $value > 0 ? $value : $fallback;
    }

    /**
     * Identify the app and the site that caused the request, so an operator seeing it in their logs
     * can attribute it and block it in robots.txt if they would rather not be fetched.
     */
    private function userAgent(): string
    {
        return sprintf('OpenPNE/4 (+%s)', rtrim((string) config('app.url'), '/'));
    }
}

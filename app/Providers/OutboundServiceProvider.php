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
     * A timeout from config, floored at one second.
     *
     * Zero means "no limit" to both Guzzle and libcurl, so an operator setting one of these to 0 —
     * or leaving an empty env value that casts to 0 — would remove the bound entirely, and the outer
     * deadline could not restore it because it is enforced by handing the remainder to these same
     * options.
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

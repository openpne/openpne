<?php

declare(strict_types=1);

namespace App\Providers;

use App\Outbound\CurlClientFactory;
use App\Outbound\DnsHostResolver;
use App\Outbound\HostResolver;
use App\Outbound\OutboundTimeout;
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
                connectTimeout: OutboundTimeout::seconds('connect_timeout', 3),
                requestTimeout: OutboundTimeout::seconds('request_timeout', 8),
                fetchTimeout: OutboundTimeout::seconds('fetch_timeout', 10),
                maxRedirects: max(0, (int) config('openpne.outbound.max_redirects')),
            );
        });
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

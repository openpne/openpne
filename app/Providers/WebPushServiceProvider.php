<?php

declare(strict_types=1);

namespace App\Providers;

use App\Outbound\PushClientFactory;
use NotificationChannels\WebPush\WebPushServiceProvider as PackageWebPushServiceProvider;
use Psr\Http\Client\ClientInterface;

/**
 * Extends rather than replaces the package provider, so its VAPID, padding and report-handler setup
 * keeps landing across releases. `dont-discover` is belt and braces: manifest providers register
 * before the ones in bootstrap/providers.php, so this later binding wins either way.
 */
class WebPushServiceProvider extends PackageWebPushServiceProvider
{
    /** @param  array<string, mixed>  $options */
    protected function webPushClient(array $options): ClientInterface
    {
        return (new PushClientFactory)->make($options);
    }
}

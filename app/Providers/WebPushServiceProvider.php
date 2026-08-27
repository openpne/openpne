<?php

declare(strict_types=1);

namespace App\Providers;

use App\Outbound\PushClientFactory;
use NotificationChannels\WebPush\WebPushServiceProvider as PackageWebPushServiceProvider;
use Psr\Http\Client\ClientInterface;

/**
 * Replaces the one thing this app cannot let the channel package decide: which HTTP client the push
 * transport runs on. Everything else it does — the VAPID auth array, padding, the report handler
 * binding, publishing — is inherited, so a future release changing that setup still lands.
 *
 * The package is listed under `dont-discover` so only this provider registers. That is belt and
 * braces rather than the mechanism: providers from the package manifest register before the ones in
 * bootstrap/providers.php, so the later contextual binding would win either way. Which also means a
 * typo in that list is silent — WebPushClientConfigTest is what actually holds this in place.
 */
class WebPushServiceProvider extends PackageWebPushServiceProvider
{
    /** @param  array<string, mixed>  $options */
    protected function webPushClient(array $options): ClientInterface
    {
        return (new PushClientFactory)->make($options);
    }
}

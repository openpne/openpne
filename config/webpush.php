<?php

use NotificationChannels\WebPush\PushSubscription;

return [

    /*
    |--------------------------------------------------------------------------
    | VAPID identity
    |--------------------------------------------------------------------------
    |
    | The per-site keypair every push is signed with. Either key empty is the
    | site-level switch for the whole feature: no shared prop, no subscribe
    | endpoint, nothing sent (App\Notifications\Push\WebPushConfig). Generate a
    | pair with `php artisan webpush:vapid --show` and copy the values into
    | OPENPNE_VAPID_PUBLIC_KEY / OPENPNE_VAPID_PRIVATE_KEY — the keys are the
    | site's identity to the push services, so replacing them invalidates every
    | stored subscription. The subject tells a push service who to contact about
    | this site's traffic; APP_URL, or a mailto: address.
    |
    */

    'vapid' => [
        'subject' => env('OPENPNE_VAPID_SUBJECT', env('APP_URL')),
        'public_key' => env('OPENPNE_VAPID_PUBLIC_KEY'),
        'private_key' => env('OPENPNE_VAPID_PRIVATE_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscription store
    |--------------------------------------------------------------------------
    |
    | One row per subscribed device. A null connection is the default one: this
    | is a single-site app, so there is nowhere else to put the table.
    |
    */

    'model' => PushSubscription::class,

    'table_name' => 'push_subscriptions',

    'database_connection' => null,

    /*
    |--------------------------------------------------------------------------
    | Push service transport
    |--------------------------------------------------------------------------
    |
    | Guzzle options for the requests Minishlink\WebPush makes. This is a second
    | egress seam beside App\Outbound, over a member-supplied endpoint URL, so
    | the shape App\Rules\PushEndpoint accepts on store has to hold at send time
    | too: a 30x is the one move that turns a validated https endpoint into a
    | request somewhere else, and the proxy environment variables Guzzle honours
    | by default would resolve the destination elsewhere again. The timeouts
    | bound a queue worker parked on an unresponsive service.
    | See docs/internals/outbound-http.md.
    |
    */

    'client_options' => [
        'allow_redirects' => false,
        'proxy' => '',
        'connect_timeout' => 5,
        'timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Payload padding
    |--------------------------------------------------------------------------
    |
    | Pad payloads to a fixed length so an observer learns nothing from their
    | size. Off trades that for bandwidth (and Firefox Android's v1 endpoint).
    |
    */

    'automatic_padding' => true,

];

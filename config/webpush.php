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
    | Options for the requests Minishlink\WebPush makes, on a client built by
    | App\Outbound\PushClientFactory. This is a second egress seam, over a
    | member-supplied endpoint URL, so the shape App\Rules\PushEndpoint accepts
    | on store has to hold at send time too. `allow_redirects` and `proxy` are
    | therefore fixed in the factory and are not loosened by anything set here,
    | short of the `curl` escape hatch, which Guzzle applies last and which can
    | say the same thing in CURLOPT_ terms: a 30x is the one move that turns a
    | validated https endpoint into a request somewhere else, and the proxy
    | environment variables Guzzle honours by default would resolve the
    | destination elsewhere again.
    |
    | `timeout` bounds one request, and the library sends a member's devices one
    | after another, so what bounds the job is timeout x MAX_DEVICES. That
    | product has to stay under WebPushNudge::$timeout, which in turn stays
    | under the queue's retry_after — past it the job is reserved a second time
    | while the first is still sending, and every reachable device is pushed
    | twice. WebPushTimeoutBudgetTest holds the arithmetic. On SQS that window
    | is the queue's visibility timeout in AWS, which this app cannot read and
    | which defaults below the ceiling: raise it past WebPushNudge::$timeout.
    |
    | On an inline queue (sync and friends) there is no job to time out at all:
    | the send runs in the request of whoever caused the notification, and this
    | product is the whole of what bounds it. Keep it small enough to be an
    | acceptable answer on its own, not merely under the ceiling.
    | See docs/internals/outbound-http.md.
    |
    */

    'client_options' => [
        'allow_redirects' => false,
        'proxy' => '',
        'connect_timeout' => 3,
        'timeout' => 5,
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

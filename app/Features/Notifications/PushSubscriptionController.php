<?php

declare(strict_types=1);

namespace App\Features\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\StorePushSubscriptionRequest;
use App\Models\Member;
use App\Notifications\Push\WebPushConfig;
use App\Rules\PushEndpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Minishlink\WebPush\ContentEncoding;

/** The client is a fire-and-forget fetch, not an Inertia visit, so both return 204 with no body. */
class PushSubscriptionController extends Controller
{
    /**
     * A browser hands out a fresh endpoint whenever its subscription is recreated and the store is writable
     * by anyone signed in, so an unbounded one would grow with every reinstall. Public because a push job's
     * budget is the per-request timeout times this cap (docs/internals/outbound-http.md, The push endpoint
     * seam).
     */
    public const MAX_DEVICES = 10;

    public function store(StorePushSubscriptionRequest $request): Response
    {
        abort_unless(WebPushConfig::configured(), 404);

        $viewer = $this->viewer();
        $viewer->updatePushSubscription(
            (string) $request->validated('endpoint'),
            (string) $request->validated('keys.p256dh'),
            (string) $request->validated('keys.auth'),
            $request->validated('contentEncoding') ?? ContentEncoding::aes128gcm->value,
        );

        $this->pruneToCap($viewer);

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        abort_unless(WebPushConfig::configured(), 404);

        // The same shape the store takes: the column is ascii, and a value it could not hold is not a
        // no-op lookup on MySQL but a collation error.
        $request->validate(['endpoint' => ['required', 'string', new PushEndpoint]]);

        // Owner-scoped by the relation: an endpoint belonging to someone else is not this member's
        // to drop, and unsubscribing a device it does not hold is a no-op either way.
        $this->viewer()->deletePushSubscription((string) $request->input('endpoint'));

        return response()->noContent();
    }

    /** Keep the newest MAX_DEVICES rows; id breaks the tie when a batch shares a timestamp. */
    private function pruneToCap(Member $viewer): void
    {
        $keep = $viewer->pushSubscriptions()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_DEVICES)
            ->pluck('id');

        $viewer->pushSubscriptions()->whereNotIn('id', $keep)->delete();
    }
}

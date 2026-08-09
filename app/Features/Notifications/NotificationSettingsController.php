<?php

namespace App\Features\Notifications;

use App\Features\Member\MemberConfigCategory;
use App\Features\Notifications\Serializers\NotificationSettingsSerializer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateNotificationSettingsRequest;
use App\Notifications\Push\WebPushConfig;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use App\Support\PushDelivery;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The member's notification-catalog opt-ins. Modern edits on a dedicated detail page (instant
 * per-toggle saves); Classic edits as a member-config category (one bulk save), rendered by
 * MemberConfigController::show — this controller owns the Modern page and the save for both.
 */
class NotificationSettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('member/config/notifications', [
            'form' => NotificationSettingsSerializer::form($this->viewer()),
            // Deliberately not called `push`: Inertia merges page props over the shared ones, so a
            // top-level `push` here would replace the shared VAPID key this page needs to subscribe.
            'pushSettings' => ['enabled' => $this->viewer()->pushDelivery() === PushDelivery::Enabled],
        ]);
    }

    public function update(UpdateNotificationSettingsRequest $request): RedirectResponse
    {
        $viewer = $this->viewer();

        foreach ($request->validated('settings') as $kind => $channels) {
            foreach ($channels as $channel => $enabled) {
                $viewer->setNotificationSetting(
                    NotificationKind::from($kind),
                    NotificationChannel::from($channel),
                    (bool) $enabled,
                );
            }
        }

        // Modern returns to the detail page silently (the inline SavedIndicator is the feedback);
        // Classic returns to its category page with the usual flash.
        return SurfaceResolver::resolve($request, 'member') === SurfaceResolver::MODERN
            ? redirect()->route('member.config.notifications.edit')
            : redirect()
                ->route('member.config', ['category' => MemberConfigCategory::Notification->value])
                ->with('status', __('Settings updated.'));
    }

    /**
     * The global push pause switch. Its own POST, like every other member-config section, so
     * pausing push never rewrites a catalog opt-in. Modern-only: the section is not rendered on
     * Classic, and with no VAPID keypair it is not rendered at all.
     */
    public function updatePush(Request $request): RedirectResponse
    {
        abort_unless(WebPushConfig::configured(), 404);

        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        $this->viewer()->setPushDelivery($enabled ? PushDelivery::Enabled : PushDelivery::Disabled);

        return redirect()->route('member.config.notifications.edit');
    }
}

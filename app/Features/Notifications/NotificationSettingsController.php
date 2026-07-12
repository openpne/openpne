<?php

namespace App\Features\Notifications;

use App\Features\Member\MemberConfigCategory;
use App\Features\Notifications\Serializers\NotificationSettingsSerializer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateNotificationSettingsRequest;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use App\Support\SurfaceResolver;
use Illuminate\Http\RedirectResponse;
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
}

<?php

namespace App\Notifications\Group;

use App\Models\Group;
use App\Models\Member;
use App\Notifications\Concerns\GatedByFeature;
use App\Notifications\FeatureNotification;
use App\Support\Feature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells the nominee an admin asked them to take over a group. Database only — the accept/reject
 * response lives on the community's own banner (OpenPNE 3 sends no mail for this), so there is no
 * mail template and no per-member catalog kind to gate.
 */
class AdminTransferRequestedNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature;
    use Queueable;

    public function __construct(
        public readonly Group $group,
        public readonly Member $requester,
    ) {}

    public static function feature(): Feature
    {
        return Feature::Group;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'group_admin_transfer_requested',
            'group_id' => $this->group->getKey(),
            'requester_id' => $this->requester->getKey(),
        ];
    }
}

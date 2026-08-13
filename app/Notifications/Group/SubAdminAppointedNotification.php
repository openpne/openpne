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
 * Tells a member an admin appointed them sub-admin. Database only: the appointment is immediate (the
 * OpenPNE 3 handshake was dropped), so there is nothing to confirm, no mail template, and no catalog
 * kind to gate.
 */
class SubAdminAppointedNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature;
    use Queueable;

    public function __construct(
        public readonly Group $group,
        public readonly Member $appointer,
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
            'kind' => 'group_sub_admin_appointed',
            'group_id' => $this->group->getKey(),
            'appointer_id' => $this->appointer->getKey(),
        ];
    }
}

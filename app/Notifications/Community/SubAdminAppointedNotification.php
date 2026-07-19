<?php

namespace App\Notifications\Community;

use App\Models\Community;
use App\Models\Member;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells a member an admin appointed them sub-admin. Database only: the appointment is immediate (the
 * OpenPNE 3 handshake was dropped), so there is nothing to confirm, no mail template, and no catalog
 * kind to gate.
 */
class SubAdminAppointedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Community $community,
        public readonly Member $appointer,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'community_sub_admin_appointed',
            'community_id' => $this->community->getKey(),
            'appointer_id' => $this->appointer->getKey(),
        ];
    }
}

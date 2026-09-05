<?php

namespace App\Notifications\Group;

use App\Features\Member\MemberDisplayName;
use App\Mail\Template\MailTemplate;
use App\Models\Group;
use App\Models\Member;
use App\Notifications\Concerns\GatedByFeature;
use App\Notifications\Concerns\RendersMailTemplate;
use App\Notifications\FeatureNotification;
use App\Support\Feature;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * No per-member catalog kind: the opt-out is the per-group toggle the recipient query applies
 * (docs/internals/notifications.md, "Gating flow").
 */
class GroupJoinedNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature;
    use Queueable;
    use RendersMailTemplate;

    public function __construct(
        public readonly Group $group,
        public readonly Member $newMember,
    ) {}

    public static function feature(): Feature
    {
        return Feature::Group;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->templateChannels(MailTemplate::GroupJoinNotice, ['database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::GroupJoinNotice, [
            'new_member' => ['name' => MemberDisplayName::of($this->newMember), 'id' => $this->newMember->getKey()],
            'community' => ['name' => $this->group->name, 'id' => $this->group->getKey()],
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'group_joined',
            'group_id' => $this->group->getKey(),
            'new_member_id' => $this->newMember->getKey(),
        ];
    }
}

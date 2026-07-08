<?php

namespace App\Notifications\Community;

use App\Mail\Template\MailTemplate;
use App\Models\Community;
use App\Models\Member;
use App\Notifications\Concerns\RendersMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a community admin a member joined. Mail + database. Unlike the catalog comment notifications
 * this has no per-member kind: the opt-out is the per-community toggle (applied by the recipient query),
 * so via() only adds the admin's global mail-template gate. The default body links the community and
 * the new member via app_url_for, so the context carries their ids as well as their names.
 */
class CommunityJoinedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersMailTemplate;

    public function __construct(
        public readonly Community $community,
        public readonly Member $newMember,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->templateChannels(MailTemplate::CommunityJoinNotice, ['database']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::CommunityJoinNotice, [
            'new_member' => ['name' => $this->newMember->name, 'id' => $this->newMember->getKey()],
            'community' => ['name' => $this->community->name, 'id' => $this->community->getKey()],
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'community_joined',
            'community_id' => $this->community->getKey(),
            'new_member_id' => $this->newMember->getKey(),
        ];
    }
}

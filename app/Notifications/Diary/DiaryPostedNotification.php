<?php

namespace App\Notifications\Diary;

use App\Features\Member\MemberDisplayName;
use App\Mail\Template\MailTemplate;
use App\Models\Diary;
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
 * The fan-out resolves each recipient's channels once and passes them, so via() returns them
 * verbatim and gates nothing (docs/internals/notifications.md, "Broadcast fan-out").
 */
class DiaryPostedNotification extends Notification implements FeatureNotification, ShouldQueue
{
    use GatedByFeature;
    use Queueable;
    use RendersMailTemplate;

    /** @param list<string> $channels */
    public function __construct(
        public readonly Diary $diary,
        public readonly Member $author,
        public readonly array $channels,
    ) {}

    public static function feature(): Feature
    {
        return Feature::Diary;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::DiaryPostedNotified, [
            'member_name' => MemberDisplayName::of($this->author),
            'diary_title' => $this->diary->title,
            'url' => route('diary.show', ['diary' => $this->diary->getKey()]),
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'diary_posted',
            'author_id' => $this->author->getKey(),
            'diary_id' => $this->diary->getKey(),
        ];
    }
}

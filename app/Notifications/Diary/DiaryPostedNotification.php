<?php

namespace App\Notifications\Diary;

use App\Mail\Template\MailTemplate;
use App\Models\Diary;
use App\Models\Member;
use App\Notifications\Concerns\RendersMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Announces a new diary to a recipient in the broadcast audience. Unlike the comment notifications this
 * does not gate itself: the fan-out job resolves each recipient's channels once (bulk, from the opt-out
 * set) and passes the decided list, so `via()` returns it verbatim — one notification instance per
 * recipient, never a per-channel duplicate of the database feed row.
 */
class DiaryPostedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RendersMailTemplate;

    /** @param list<string> $channels the pre-resolved delivery channels (mail and/or database). */
    public function __construct(
        public readonly Diary $diary,
        public readonly Member $author,
        public readonly array $channels,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->mailFromTemplate(MailTemplate::DiaryPostedNotified, [
            'member_name' => $this->author->name,
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

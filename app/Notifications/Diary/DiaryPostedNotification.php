<?php

namespace App\Notifications\Diary;

use App\Features\Block\BlockLookup;
use App\Features\Diary\DiaryAccess;
use App\Features\Member\MemberDisplayName;
use App\Mail\Template\MailTemplate;
use App\Models\Diary;
use App\Models\Member;
use App\Notifications\Concerns\GatedByFeature;
use App\Notifications\Concerns\RendersMailTemplate;
use App\Notifications\FeatureNotification;
use App\Support\BodyRenderer;
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
    use GatedByFeature {
        shouldSend as private featureShouldSend;
    }
    use Queueable;
    use RendersMailTemplate;

    /** A diary deleted with its author while queued cannot be restored, and there is nothing left to announce. */
    public bool $deleteWhenMissingModels = true;

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

    /**
     * SerializesModels hands this fresh rows, so a diary narrowed, a ban or a block landing while
     * queued is not delivered; the block is checked both ways, as the audience query excludes it.
     */
    public function shouldSend(Member $notifiable, string $channel): bool
    {
        return $this->featureShouldSend($notifiable, $channel)
            && ! $notifiable->is_login_rejected
            && DiaryAccess::canView($notifiable, $this->diary)
            && ! BlockLookup::hasAnyBlockBetween($notifiable, $this->author);
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
            'body' => BodyRenderer::plainText($this->diary->body, $this->diary->format),
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

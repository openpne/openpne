<?php

namespace App\Notifications\Auth;

use App\Features\Auth\RegistrationTokenSource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The "complete your registration" mail, sent to an address with no Member yet (on-demand, so the
 * notifiable is an AnonymousNotifiable). Carries the raw token in the link only; the locale is
 * captured at request time because the notification renders later on the queue. The opening copy
 * varies by how the token was issued (self / member invite / admin invite); the link, expiry, and
 * sender stay shared so the three never drift.
 */
class RegistrationLinkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $rawToken,
        string $locale,
        public readonly RegistrationTokenSource $source = RegistrationTokenSource::Selfservice,
        public readonly ?string $inviterName = null,
        public readonly ?string $message = null,
    ) {
        // The base Notification's $locale (applied when the mail renders on the queue); not a
        // promoted property, which would clash with that base property.
        $this->locale($locale);
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $hours = (int) ceil(config('openpne.registration.token_ttl_minutes') / 60);

        $mail = (new MailMessage)
            ->from(sns_admin_mail_address(), sns_name())
            ->subject($this->subject());

        foreach ($this->intro() as $line) {
            $mail->line($line);
        }

        return $mail
            ->action(__('Continue registration'), url('/register/'.$this->rawToken))
            ->line(__('This link expires in :hours hours.', ['hours' => $hours]))
            ->salutation('— '.sns_name());
    }

    private function subject(): string
    {
        return match ($this->source) {
            RegistrationTokenSource::Selfservice => __('Complete your :app registration', ['app' => sns_name()]),
            default => __('You are invited to join :app', ['app' => sns_name()]),
        };
    }

    /** @return list<string> */
    private function intro(): array
    {
        // The inviter's display name and personal note are member-supplied free text rendered as a
        // markdown line, so escape them: the mail template HTML-escapes the line, but raw markdown
        // would otherwise let a member slip a live link or remote image into a mail sent from the
        // site's trusted address.
        $inviter = $this->inviterName !== null ? self::markdownSafe($this->inviterName) : null;

        $lines = match ($this->source) {
            RegistrationTokenSource::Selfservice => [__('Open the link below to finish creating your account.')],
            RegistrationTokenSource::MemberInvite => [
                $inviter !== null
                    ? __(':inviter has invited you to join :app.', ['inviter' => $inviter, 'app' => sns_name()])
                    : __('You have been invited to join :app.', ['app' => sns_name()]),
                __('Open the link below to finish creating your account.'),
            ],
            RegistrationTokenSource::AdminInvite => [
                __('You have been invited to join :app.', ['app' => sns_name()]),
                __('Open the link below to finish creating your account.'),
            ],
        };

        // A member invite may carry a personal note; show it before the call to action.
        if ($this->message !== null && trim($this->message) !== '') {
            $lines[] = __('Message from :inviter:', ['inviter' => $inviter ?? sns_name()]);
            $lines[] = self::markdownSafe(trim($this->message));
        }

        return $lines;
    }

    /** Backslash-escape CommonMark ASCII punctuation so member text renders literally, not as markup. */
    private static function markdownSafe(string $text): string
    {
        return preg_replace('/([!"#$%&\'()*+,\-.\/:;<=>?@\[\\\\\]^_`{|}~])/', '\\\\$1', $text);
    }
}

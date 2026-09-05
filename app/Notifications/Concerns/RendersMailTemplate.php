<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Models\Member;
use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A single text/plain part with no HTML or markdown shell, matching OpenPNE 3, so a member-supplied value
 * can never emit a link, image or script. Linkifying a bare URL or address is the receiving mail client's
 * own doing.
 */
trait RendersMailTemplate
{
    /**
     * `$others` pass through unchanged, so the in-app record survives a mail the admin turned off.
     *
     * @param  list<string>  $others
     * @return list<string>
     */
    protected function templateChannels(MailTemplate $template, array $others = []): array
    {
        return app(MailTemplateService::class)->isEnabled($template)
            ? ['mail', ...$others]
            : $others;
    }

    /**
     * @param  list<string>  $others
     * @return list<string>
     */
    protected function templateChannelsFor(MailTemplate $template, NotificationKind $kind, Member $notifiable, array $others = []): array
    {
        $channels = $notifiable->wantsNotification($kind, NotificationChannel::Mail)
            ? $this->templateChannels($template)
            : [];

        foreach ($others as $channel) {
            if ($channel === 'database' && ! $notifiable->wantsNotification($kind, NotificationChannel::Web)) {
                continue;
            }

            $channels[] = $channel;
        }

        return $channels;
    }

    /** @param array<string, mixed> $context */
    protected function mailFromTemplate(MailTemplate $template, array $context = []): MailMessage
    {
        // The notification's own locale, not the ambient one: a real send wraps render() in
        // withLocale($this->locale) so the two already agree, but a direct toMail() (tests, preview) is
        // not wrapped — reading $this->locale keeps the rendered language deterministic either way.
        $locale = $this->locale ?? app()->getLocale();
        $rendered = app(MailTemplateService::class)->render($template, $locale, $context);

        return (new MailMessage)
            ->from(sns_admin_mail_address(), sns_name())
            ->subject($rendered->subject)
            ->view(['text' => 'mail.template-text'], ['body' => $rendered->body]);
    }
}

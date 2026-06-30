<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Builds a notification mail from an admin-editable MailTemplate: render the subject/body for the active
 * locale (Laravel's withLocale() makes app()->getLocale() the notification's locale during send), then
 * deliver through the dedicated `mail.template` views — NOT the markdown shell, so the OpenPNE 3 body text
 * is never re-interpreted as Markdown and a member-supplied value cannot inject a link/image.
 */
trait RendersMailTemplate
{
    /**
     * Channels for a template-bodied notification: 'mail' only while the template is enabled. A
     * configurable template an admin turned off (mail_templates.is_enabled = 0) drops the mail; required
     * templates are always enabled. $others (e.g. 'database') pass through unchanged so the in-app record
     * survives even when the mail is off.
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

    /** @param array<string, mixed> $context */
    protected function mailFromTemplate(MailTemplate $template, array $context = []): MailMessage
    {
        $rendered = app(MailTemplateService::class)->render($template, app()->getLocale(), $context);

        return (new MailMessage)
            ->from(sns_admin_mail_address(), sns_name())
            ->subject($rendered->subject)
            ->view(['html' => 'mail.template', 'text' => 'mail.template-text'], ['body' => $rendered->body]);
    }
}

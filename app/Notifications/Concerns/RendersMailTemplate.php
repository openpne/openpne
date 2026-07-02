<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Builds a notification mail from an admin-editable MailTemplate: render the subject/body in the
 * notification's captured locale, then deliver as a single text/plain part (the `mail.template-text`
 * view). No HTML/markdown shell — matching OpenPNE 3 — so the body is never re-interpreted and a
 * member-supplied value cannot become a live link. Linkifying bare URLs/emails is left to the
 * receiving mail client, exactly as OpenPNE 3 did.
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

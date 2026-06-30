<?php

declare(strict_types=1);

namespace App\Mail\Template;

/**
 * The closed registry of OpenPNE 4 system-mail templates (OpenPNE 3 "NotificationMail"). The case value
 * is the stored `mail_templates.key`. Each case declares its OpenPNE 3 import origin, whether an admin
 * may disable it, whether it is a sendable mail or the appended signature, its admin caption + variable
 * hints, and its built-in per-locale default wording (MailTemplateDefaults).
 *
 * Required/security mails (registration, password, email change) are NOT configurable: the service
 * treats them as always-enabled and the OpenPNE 3 import does not carry their is_enabled, so a migrated
 * `is_enabled=0` can never break those flows.
 */
enum MailTemplate: string
{
    case RegistrationLink = 'registration-link';
    case PasswordReset = 'password-reset';
    case EmailChangeConfirm = 'email-change-confirm';
    case EmailChangeNotice = 'email-change-notice';
    case FriendRequested = 'friend-requested';
    case FriendAccepted = 'friend-accepted';
    case MessageReceived = 'message-received';

    /** Not a sendable mail: rendered and appended to every sendable body by MailTemplateService. */
    case Signature = 'signature';

    /** The OpenPNE 3 `notification_mail.name` (pc_*) this template imports from, or null when there is none. */
    public function op3SourceName(): ?string
    {
        return match ($this) {
            self::RegistrationLink => 'pc_requestRegisterURL',
            self::EmailChangeConfirm => 'pc_changeMailAddress',
            self::FriendAccepted => 'pc_friendLinkComplete',
            self::Signature => 'pc_signature',
            self::PasswordReset, self::EmailChangeNotice, self::FriendRequested, self::MessageReceived => null,
        };
    }

    /** Whether an admin may turn this mail off. Required/security mails and the signature are not toggleable. */
    public function isConfigurable(): bool
    {
        return match ($this) {
            self::FriendRequested, self::FriendAccepted, self::MessageReceived => true,
            self::RegistrationLink, self::PasswordReset, self::EmailChangeConfirm, self::EmailChangeNotice,
            self::Signature => false,
        };
    }

    /** A real outgoing mail (vs the signature, which is appended to other bodies). */
    public function isSendable(): bool
    {
        return $this !== self::Signature;
    }

    /** Admin-facing caption (the editor's section heading). */
    public function caption(): string
    {
        return match ($this) {
            self::RegistrationLink => __('Registration link'),
            self::PasswordReset => __('Password reset'),
            self::EmailChangeConfirm => __('Email address change (confirmation)'),
            self::EmailChangeNotice => __('Email address change (notice)'),
            self::FriendRequested => __('Friend request'),
            self::FriendAccepted => __('Friend request accepted'),
            self::MessageReceived => __('Message received'),
            self::Signature => __('Signature'),
        };
    }

    /**
     * The template-specific variables a body/subject may reference, as the bare names the admin writes
     * inside `{{ … }}`. The OpenPNE 3 globals (op_config.sns_name, op_term.*) are available everywhere and
     * are not repeated per template.
     *
     * @return list<string>
     */
    public function variables(): array
    {
        return match ($this) {
            self::RegistrationLink => ['name', 'message', 'token', 'authMode'],
            self::PasswordReset => ['url'],
            self::EmailChangeConfirm => ['token', 'id', 'type'],
            self::EmailChangeNotice => ['new_email'],
            self::FriendRequested => ['member.name', 'url'],
            self::FriendAccepted => ['member.name'],
            self::MessageReceived => ['member.name', 'url'],
            self::Signature => [],
        };
    }

    public function defaultSubject(string $locale): ?string
    {
        return self::defaults($this)[$this->localeKey($locale)]['subject'];
    }

    public function defaultBody(string $locale): string
    {
        return self::defaults($this)[$this->localeKey($locale)]['body'];
    }

    /** ja for any ja-* locale, en otherwise — the two locales the defaults are authored in. */
    private function localeKey(string $locale): string
    {
        return str_starts_with($locale, 'ja') ? 'ja' : 'en';
    }

    /** @return array{ja: array{subject: ?string, body: string}, en: array{subject: ?string, body: string}} */
    private static function defaults(self $template): array
    {
        return MailTemplateDefaults::all()[$template->value];
    }

    /** @return list<self> the sendable templates (everything but the signature). */
    public static function sendable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $t): bool => $t->isSendable()));
    }

    /**
     * The templates the OpenPNE 3 import carries (those with a source name). The SSoT for the upgrade
     * steps' name filter and key remap, so adding an import origin to a case is all it takes.
     *
     * @return list<self>
     */
    public static function importable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $t): bool => $t->op3SourceName() !== null));
    }

    /** Resolve a template by its OpenPNE 3 source name, or null. */
    public static function fromOp3SourceName(string $name): ?self
    {
        foreach (self::cases() as $template) {
            if ($template->op3SourceName() === $name) {
                return $template;
            }
        }

        return null;
    }
}

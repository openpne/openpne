<?php

declare(strict_types=1);

namespace App\Mail\Template;

use Illuminate\Support\Arr;

/**
 * The closed registry of OpenPNE 4 system-mail templates (OpenPNE 3 "NotificationMail"). The case value
 * is the stored `mail_templates.key`. Each case's full registry entry lives in definition() — its
 * OpenPNE 3 import origin, whether an admin may disable it, its admin caption, and its variable set
 * (help + representative sample per variable). The accessors below are thin views over that one entry.
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
    case DiaryCommentReceived = 'diary-comment';
    case DiaryPostedNotified = 'diary-posted';
    case TimelineMentionNotified = 'timeline-mention';
    case CommunityPostingNotified = 'community-posting';
    case CommunityJoinNotice = 'community-join';
    case RegistrationCompleted = 'registration-complete';
    case WithdrawalCompleted = 'withdrawal-complete';
    case WithdrawalAdminNotice = 'withdrawal-admin-notice';
    case PasswordChanged = 'password-changed';
    case MfaEnabled = 'mfa-enabled';
    case MfaDisabled = 'mfa-disabled';
    case MfaResetLink = 'mfa-reset-link';

    /** Not a sendable mail: rendered and appended to every sendable body by MailTemplateService. */
    case Signature = 'signature';

    /**
     * The full registry entry, colocated so adding/changing a template is one arm here. Untranslated:
     * caption and variable help are source strings; __() is applied in the accessors. Variable keys are
     * dot paths ({{ member.name }} → 'member.name'); see MailTemplateDefinition.
     */
    public function definition(): MailTemplateDefinition
    {
        return match ($this) {
            self::RegistrationLink => new MailTemplateDefinition(
                op3SourceName: 'pc_requestRegisterURL',
                isConfigurable: false,
                caption: 'Registration link',
                variables: [
                    'name' => ['help' => 'The inviter’s name (member or admin invitations).', 'sample' => 'Example'],
                    'message' => ['help' => 'The optional message from the inviter.', 'sample' => 'Example'],
                    'token' => ['help' => 'The registration token (used by the app_url_for link).', 'sample' => 'example-token'],
                    'authMode' => ['help' => 'The authentication mode.', 'sample' => 'MailAddress'],
                ],
            ),
            self::PasswordReset => new MailTemplateDefinition(
                op3SourceName: null,
                isConfigurable: false,
                caption: 'Password reset',
                variables: [
                    'url' => ['help' => 'The password reset URL.', 'sample' => 'https://example.test/reset'],
                ],
            ),
            self::EmailChangeConfirm => new MailTemplateDefinition(
                op3SourceName: 'pc_changeMailAddress',
                isConfigurable: false,
                caption: 'Email address change (confirmation)',
                variables: [
                    'token' => ['help' => 'The confirmation token (used by the app_url_for link).', 'sample' => 'example-token'],
                    'id' => ['help' => 'The member ID.', 'sample' => 1],
                    'type' => ['help' => 'The address type.', 'sample' => 'pc_address'],
                ],
            ),
            self::EmailChangeNotice => new MailTemplateDefinition(
                op3SourceName: null,
                isConfigurable: false,
                caption: 'Email address change (notice)',
                variables: [
                    'new_email' => ['help' => 'The new email address.', 'sample' => 'new@example.test'],
                    'cancel_url' => ['help' => 'The link that cancels the pending change.', 'sample' => 'https://example.test/member/config/email/cancel/token'],
                ],
            ),
            self::FriendRequested => new MailTemplateDefinition(
                op3SourceName: 'pc_friendLinkRequest',
                isConfigurable: true,
                caption: '%Friend% request',
                variables: [
                    'member.name' => ['help' => 'The requester’s name.', 'sample' => 'Example'],
                    'url' => ['help' => 'The %friend% management URL.', 'sample' => 'https://example.test'],
                ],
            ),
            self::FriendAccepted => new MailTemplateDefinition(
                op3SourceName: 'pc_friendLinkComplete',
                isConfigurable: true,
                caption: '%Friend% request accepted',
                variables: [
                    'member.name' => ['help' => 'The name of the member who accepted.', 'sample' => 'Example'],
                ],
            ),
            self::MessageReceived => new MailTemplateDefinition(
                op3SourceName: 'pc_notifyNewMessage',
                isConfigurable: true,
                caption: 'Message received',
                variables: [
                    'member.name' => ['help' => 'The sender’s name.', 'sample' => 'Example'],
                    // Flat names used by the OpenPNE 3 notification extension's wording, kept
                    // alongside so an imported body renders.
                    'member_name' => ['help' => 'The sender’s name.', 'sample' => 'Example'],
                    'message_subject' => ['help' => 'The message subject.', 'sample' => 'Example subject'],
                    'message_body' => ['help' => 'The message body.', 'sample' => 'Example body'],
                    'url' => ['help' => 'The message URL.', 'sample' => 'https://example.test'],
                ],
            ),
            self::DiaryCommentReceived => new MailTemplateDefinition(
                op3SourceName: 'pc_notifyNewDiaryComment',
                // Not admin-toggleable (matching the source template); the member-level opt-out
                // lives in the notification settings instead.
                isConfigurable: false,
                caption: '%Diary% Comment',
                variables: [
                    'member_name' => ['help' => 'The commenter’s name.', 'sample' => 'Example'],
                    'diary_title' => ['help' => 'The %diary% title.', 'sample' => 'Example title'],
                    'body' => ['help' => 'The comment body.', 'sample' => 'Example body'],
                    'url' => ['help' => 'The %diary% URL.', 'sample' => 'https://example.test'],
                ],
            ),
            self::DiaryPostedNotified => new MailTemplateDefinition(
                op3SourceName: 'pc_notifyNewDiary',
                // Not admin-toggleable (matching the source template); the member-level opt-out
                // (diary-new-post / friends-only) lives in the notification settings instead.
                isConfigurable: false,
                caption: 'New %Diary%',
                variables: [
                    'member_name' => ['help' => 'The author’s name.', 'sample' => 'Example'],
                    'diary_title' => ['help' => 'The %diary% title.', 'sample' => 'Example title'],
                    'url' => ['help' => 'The %diary% URL.', 'sample' => 'https://example.test'],
                ],
            ),
            self::TimelineMentionNotified => new MailTemplateDefinition(
                // OpenPNE-4-only: OpenPNE 3's timeline had no @mentions, so there is no source
                // wording to carry — the default is authored here.
                op3SourceName: null,
                isConfigurable: true,
                caption: 'Mentioned in a %activity% post',
                variables: [
                    'member_name' => ['help' => 'The author’s name.', 'sample' => 'Example'],
                    'body' => ['help' => 'The posted content.', 'sample' => 'Example body'],
                    'url' => ['help' => 'The %activity% post URL.', 'sample' => 'https://example.test'],
                ],
            ),
            self::CommunityPostingNotified => new MailTemplateDefinition(
                op3SourceName: 'pc_notifyCommunityPosting',
                isConfigurable: true,
                // One template for every community-board notification (topic and event comments
                // and the new-post broadcasts), matching the source template — a second case
                // could not import the same source row.
                caption: 'Notification of %Community% Posting',
                variables: [
                    'community_name' => ['help' => 'The %community% name.', 'sample' => 'Example community'],
                    'topic_name' => ['help' => 'The %topic% or event title.', 'sample' => 'Example title'],
                    'nickname' => ['help' => 'The poster’s name.', 'sample' => 'Example'],
                    'body' => ['help' => 'The posted content.', 'sample' => 'Example body'],
                    'url' => ['help' => 'The %topic% or event URL.', 'sample' => 'https://example.test'],
                ],
            ),
            self::CommunityJoinNotice => new MailTemplateDefinition(
                op3SourceName: 'pc_joinCommunity',
                isConfigurable: true,
                caption: 'Notification of Someone’s Joining Your %Community%',
                variables: [
                    // The default body builds its links with app_url_for from these ids, so both the
                    // name and the id are declared (MailUrlMapper resolves @community_home / @member_profile).
                    'new_member.name' => ['help' => 'The joining member’s name.', 'sample' => 'Example'],
                    'new_member.id' => ['help' => 'The joining member’s ID.', 'sample' => 1],
                    'community.name' => ['help' => 'The %community% name.', 'sample' => 'Example community'],
                    'community.id' => ['help' => 'The %community% ID.', 'sample' => 1],
                ],
            ),
            self::RegistrationCompleted => new MailTemplateDefinition(
                op3SourceName: 'pc_registerEnd',
                // Non-configurable in OpenPNE 3 (registerEnd configurable:false) — a transactional
                // "your account is ready" mail with no admin toggle and no member opt-out.
                isConfigurable: false,
                caption: 'Registration complete',
                variables: [
                    'url' => ['help' => 'The home URL.', 'sample' => 'https://example.test'],
                ],
            ),
            self::WithdrawalCompleted => new MailTemplateDefinition(
                op3SourceName: 'pc_leave',
                // Non-configurable in OpenPNE 3 (leave configurable:false). Sent to the just-deleted
                // member's captured address, so it carries no in-app record and no member opt-out.
                isConfigurable: false,
                caption: 'Withdrawal complete',
                variables: [
                    'member.name' => ['help' => 'The withdrawing member’s name.', 'sample' => 'Example'],
                ],
            ),
            self::WithdrawalAdminNotice => new MailTemplateDefinition(
                // OpenPNE-4-only: OpenPNE 3's admin withdrawal notice was a global (non-NotificationMail)
                // template, so there is no source wording to carry — the default is authored here.
                op3SourceName: null,
                isConfigurable: false,
                caption: 'Member withdrawal (admin notice)',
                variables: [
                    'member.name' => ['help' => 'The withdrawing member’s name.', 'sample' => 'Example'],
                    'member.email' => ['help' => 'The withdrawing member’s email address.', 'sample' => 'member@example.test'],
                    'member.id' => ['help' => 'The withdrawing member’s ID.', 'sample' => 1],
                ],
            ),
            self::PasswordChanged => new MailTemplateDefinition(
                // OpenPNE-4-only security alert (takeover detection); OpenPNE 3 had no such mail.
                op3SourceName: null,
                isConfigurable: false,
                caption: 'Password changed',
                variables: [],
            ),
            self::MfaEnabled => new MailTemplateDefinition(
                op3SourceName: null,
                isConfigurable: false,
                caption: 'Two-factor authentication enabled',
                variables: [],
            ),
            self::MfaDisabled => new MailTemplateDefinition(
                op3SourceName: null,
                isConfigurable: false,
                caption: 'Two-factor authentication disabled',
                variables: [],
            ),
            self::MfaResetLink => new MailTemplateDefinition(
                // OpenPNE-4-only: the admin-issued two-factor reset link (TASK-122); OpenPNE 3 had no such flow.
                op3SourceName: null,
                isConfigurable: false,
                caption: 'Two-factor authentication reset (link)',
                variables: [
                    'url' => ['help' => 'The two-factor reset URL.', 'sample' => 'https://example.test/member/mfa/reset/token'],
                ],
            ),
            self::Signature => new MailTemplateDefinition(
                op3SourceName: 'pc_signature',
                isConfigurable: false,
                caption: 'Signature',
                variables: [],
            ),
        };
    }

    /** The OpenPNE 3 `notification_mail.name` (pc_*) this template imports from, or null when there is none. */
    public function op3SourceName(): ?string
    {
        return $this->definition()->op3SourceName;
    }

    /** Whether an admin may turn this mail off. Required/security mails and the signature are not toggleable. */
    public function isConfigurable(): bool
    {
        return $this->definition()->isConfigurable;
    }

    /** A real outgoing mail (vs the signature, which is appended to other bodies). */
    public function isSendable(): bool
    {
        return $this !== self::Signature;
    }

    /** Admin-facing caption (the editor's section heading). */
    public function caption(): string
    {
        return __($this->definition()->caption);
    }

    /**
     * The template-specific variables a body/subject may reference, as the bare names the admin writes
     * inside `{{ … }}`. Keys of the definition's variable set, so the name list cannot drift from the help.
     *
     * @return list<string>
     */
    public function variables(): array
    {
        return array_keys($this->definition()->variables);
    }

    /**
     * Each template-specific variable with a short description, for the editor's help. The OpenPNE 3
     * globals (op_config.sns_name, op_term.*) are available everywhere and are not repeated per template.
     *
     * @return array<string, string> `{{ name }}` token => description
     */
    public function variableHelp(): array
    {
        return array_map(static fn (array $v): string => __($v['help']), $this->definition()->variables);
    }

    /**
     * Raw source strings (pre-__()): every caption and variable-help string across all templates.
     * Exposed so the i18n:check term-literal gate can scan strings that reach __() via a variable
     * and never enter the code scanner.
     *
     * @return list<string>
     */
    public static function sourceStrings(): array
    {
        $strings = [];
        foreach (self::cases() as $template) {
            $definition = $template->definition();
            $strings[] = $definition->caption;
            foreach ($definition->variables as $variable) {
                $strings[] = $variable['help'];
            }
        }

        return $strings;
    }

    /**
     * Every caption and variable-help string surfaces in the admin mail-template editor, so the
     * coverage subset is the full source set.
     *
     * @return list<string>
     */
    public static function coverageStrings(): array
    {
        return self::sourceStrings();
    }

    /**
     * Dummy values for this template's variables, enough to render it once for a syntax check: a token so
     * `app_url_for` resolves (its absence would throw a missing-token error, not a template fault) and a
     * value for each declared variable. Derived from the same variable set as variableHelp() and undotted
     * to the nested shape the renderer sees (`member.name` sample → `['member' => ['name' => …]]`).
     *
     * @return array<string, mixed>
     */
    public function representativeContext(): array
    {
        return Arr::undot(array_map(static fn (array $v): mixed => $v['sample'], $this->definition()->variables));
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

<?php

declare(strict_types=1);

namespace App\Mail\Template;

use Illuminate\Support\Arr;

/**
 * The closed registry of OpenPNE 4 system-mail templates (OpenPNE 3 "NotificationMail"); the case value is
 * the stored `mail_templates.key`.
 */
enum MailTemplate: string
{
    case RegistrationLink = 'registration-link';
    case PasswordReset = 'password-reset';
    case EmailChangeConfirm = 'email-change-confirm';
    case EmailChangeNotice = 'email-change-notice';
    case FriendRequested = 'friend-requested';
    case FriendAccepted = 'friend-accepted';
    case DirectMessageReceived = 'direct-message-received';
    case DiaryCommentReceived = 'diary-comment';
    case DiaryPostedNotified = 'diary-posted';
    case TimelineMentionNotified = 'timeline-mention';

    case GroupTalkMentionNotified = 'group-talk-mention';
    case GroupTalkMessageNotified = 'group-talk-message';
    case TimelinePostingNotified = 'timeline-posting';
    case GroupPostingNotified = 'group-posting';
    case GroupJoinNotice = 'group-join';
    case RegistrationCompleted = 'registration-complete';
    case WithdrawalCompleted = 'withdrawal-complete';
    case WithdrawalAdminNotice = 'withdrawal-admin-notice';
    case PasswordChanged = 'password-changed';
    case MfaEnabled = 'mfa-enabled';
    case MfaDisabled = 'mfa-disabled';
    case MfaResetLink = 'mfa-reset-link';

    case Signature = 'signature';

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
            self::DirectMessageReceived => new MailTemplateDefinition(
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
                // OpenPNE 3's timeline had no @mentions, so there is no source wording to import.
                op3SourceName: null,
                isConfigurable: true,
                caption: 'Mentioned in a %activity% post',
                variables: [
                    'member_name' => ['help' => 'The author’s name.', 'sample' => 'Example'],
                    'body' => ['help' => 'The posted content.', 'sample' => 'Example body'],
                    'url' => ['help' => 'The %activity% post URL.', 'sample' => 'https://example.test'],
                ],
            ),
            self::GroupTalkMentionNotified => new MailTemplateDefinition(
                // OpenPNE 3 had no group chat, so there is no source wording to import.
                op3SourceName: null,
                isConfigurable: true,
                caption: 'Mentioned in a %community% talk message',
                variables: [
                    'member_name' => ['help' => 'The author’s name.', 'sample' => 'Example'],
                    'community_name' => ['help' => 'The %community% the message was posted in.', 'sample' => 'Example %community%'],
                    'body' => ['help' => 'The posted content.', 'sample' => 'Example body'],
                    // Talk has no per-message screen, so the link opens the conversation at the message.
                    'url' => ['help' => 'The %community% talk URL.', 'sample' => 'https://example.test'],
                ],
            ),
            self::GroupTalkMessageNotified => new MailTemplateDefinition(
                op3SourceName: null,
                isConfigurable: true,
                caption: 'New %community% talk message',
                variables: [
                    'member_name' => ['help' => 'The author’s name.', 'sample' => 'Example'],
                    'community_name' => ['help' => 'The %community% the message was posted in.', 'sample' => 'Example %community%'],
                    'body' => ['help' => 'The posted content.', 'sample' => 'Example body'],
                    'url' => ['help' => 'The %community% talk URL.', 'sample' => 'https://example.test'],
                ],
            ),
            self::TimelinePostingNotified => new MailTemplateDefinition(
                op3SourceName: 'pc_timelineNewPost',
                isConfigurable: true,
                // One template for every timeline broadcast (new posts and replies), matching the
                // source template — a second case could not import the same source row.
                caption: 'Notification of %Activity% Posting',
                variables: [
                    'member_name' => ['help' => 'The author’s name.', 'sample' => 'Example'],
                    // The name OpenPNE 3's wording used, kept alongside so an imported body renders.
                    'author' => ['help' => 'The author’s name.', 'sample' => 'Example'],
                    'body' => ['help' => 'The posted content.', 'sample' => 'Example body'],
                    'url' => ['help' => 'The %activity% post URL.', 'sample' => 'https://example.test'],
                ],
            ),
            self::GroupPostingNotified => new MailTemplateDefinition(
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
            self::GroupJoinNotice => new MailTemplateDefinition(
                op3SourceName: 'pc_joinCommunity',
                isConfigurable: true,
                caption: 'Notification of Someone’s Joining Your %Community%',
                variables: [
                    // The default body builds its links with `app_url_for` from these ids, so each id is
                    // declared beside its name.
                    'new_member.name' => ['help' => 'The joining member’s name.', 'sample' => 'Example'],
                    'new_member.id' => ['help' => 'The joining member’s ID.', 'sample' => 1],
                    'community.name' => ['help' => 'The %community% name.', 'sample' => 'Example community'],
                    'community.id' => ['help' => 'The %community% ID.', 'sample' => 1],
                ],
            ),
            self::RegistrationCompleted => new MailTemplateDefinition(
                op3SourceName: 'pc_registerEnd',
                // Non-configurable in OpenPNE 3 (`registerEnd` configurable:false).
                isConfigurable: false,
                caption: 'Registration complete',
                variables: [
                    'url' => ['help' => 'The home URL.', 'sample' => 'https://example.test'],
                ],
            ),
            self::WithdrawalCompleted => new MailTemplateDefinition(
                op3SourceName: 'pc_leave',
                // Non-configurable in OpenPNE 3 (`leave` configurable:false).
                isConfigurable: false,
                caption: 'Withdrawal complete',
                variables: [
                    'member.name' => ['help' => 'The withdrawing member’s name.', 'sample' => 'Example'],
                ],
            ),
            self::WithdrawalAdminNotice => new MailTemplateDefinition(
                // OpenPNE 3's admin withdrawal notice was a global template rather than a
                // NotificationMail, so there is no source wording to import.
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
                // OpenPNE 3 had no such mail.
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
                // OpenPNE 3 had no such flow.
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

    public function isConfigurable(): bool
    {
        return $this->definition()->isConfigurable;
    }

    public function isSendable(): bool
    {
        return $this !== self::Signature;
    }

    public function caption(): string
    {
        return __($this->definition()->caption);
    }

    /** @return list<string> */
    public function variables(): array
    {
        return array_keys($this->definition()->variables);
    }

    /**
     * The OpenPNE 3 globals (op_config.sns_name, op_term.*) are available to every template and are not
     * repeated per template.
     *
     * @return array<string, string> `{{ name }}` token => description
     */
    public function variableHelp(): array
    {
        return array_map(static fn (array $v): string => __($v['help']), $this->definition()->variables);
    }

    /**
     * Read by the i18n:check coverage gate, which no call site reaches (docs/internals/i18n.md, CI gate).
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
     * Every declared variable carries a sample, the token ones included: an absent token would throw a
     * missing-token error rather than the template fault a syntax check is looking for.
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

    private function localeKey(string $locale): string
    {
        return str_starts_with($locale, 'ja') ? 'ja' : 'en';
    }

    /** @return array{ja: array{subject: ?string, body: string}, en: array{subject: ?string, body: string}} */
    private static function defaults(self $template): array
    {
        return MailTemplateDefaults::all()[$template->value];
    }

    /** @return list<self> */
    public static function sendable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $t): bool => $t->isSendable()));
    }

    /** @return list<self> */
    public static function importable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $t): bool => $t->op3SourceName() !== null));
    }

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

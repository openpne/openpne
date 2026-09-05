<?php

declare(strict_types=1);

namespace App\Mail\Template;

/**
 * Bodies are read verbatim from `resources/mail-templates/{locale}/{key}.twig` so the OpenPNE 3 text stays
 * byte-exact; subjects are single lines kept here, null only for the non-sendable signature. A template
 * with an OpenPNE 3 origin carries that project's `sample:` text verbatim
 * (`OpenPNE3/lib/config/config/mail_template.yml`).
 */
final class MailTemplateDefaults
{
    /** @var array<string, array{en: ?string, ja: ?string}> */
    private const SUBJECTS = [
        'registration-link' => [
            'en' => '{{ op_config.sns_name }} Letter of invitation',
            'ja' => '{{ op_config.sns_name }}招待状',
        ],
        'email-change-confirm' => [
            'en' => 'Information of a mail address change page',
            'ja' => 'メールアドレス変更ページのお知らせ',
        ],
        'friend-accepted' => [
            'en' => '{{ member.name }} accepted your {{ op_term.friend }} link request',
            'ja' => '{{ member.name }} さんがあなたの{{ op_term.friend }}リンクリクエストを承認しました',
        ],
        'password-reset' => [
            'en' => 'Reset your password',
            'ja' => 'パスワードのリセット',
        ],
        'email-change-notice' => [
            'en' => 'Your email address change was requested',
            'ja' => 'メールアドレス変更のリクエストを受け付けました',
        ],
        'friend-requested' => [
            'en' => 'You have a new {{ op_term.friend }} request',
            'ja' => '{{ op_term.friend }}リクエストが届きました',
        ],
        'direct-message-received' => [
            'en' => 'You have a new message',
            'ja' => '新しいメッセージが届きました',
        ],
        'diary-comment' => [
            'en' => 'New comment on "{{ diary_title }}"',
            'ja' => '【{{ op_config.sns_name }}】新着日記コメント「{{ diary_title }}」',
        ],
        'diary-posted' => [
            'en' => 'New diary from {{ member_name }}: "{{ diary_title }}"',
            'ja' => '【{{ op_config.sns_name }}】新着日記「{{ diary_title }}」',
        ],
        'timeline-mention' => [
            'en' => '{{ member_name }} mentioned you',
            'ja' => '【{{ op_config.sns_name }}】{{ member_name }} さんからのメンション',
        ],
        'group-talk-mention' => [
            'en' => '{{ member_name }} mentioned you in {{ community_name }}',
            'ja' => '【{{ op_config.sns_name }}】{{ community_name }} で {{ member_name }} さんからのメンション',
        ],
        'group-talk-message' => [
            'en' => '{{ member_name }} posted in {{ community_name }}',
            'ja' => '【{{ op_config.sns_name }}】{{ community_name }} のトークに {{ member_name }} さんが投稿',
        ],
        'timeline-posting' => [
            'en' => 'New {{ op_term.activity }} post from {{ member_name }}',
            // The OpenPNE 3 extension's sample, byte for byte — including the space after 】.
            'ja' => '【{{ op_config.sns_name }}】 {{ author }}さんのタイムライン投稿',
        ],
        'group-posting' => [
            'en' => '[{{ op_config.sns_name }}] {{ community_name }} {{ topic_name }}',
            'ja' => '【{{ op_config.sns_name }}】{{ community_name }} {{ topic_name }}',
        ],
        'group-join' => [
            'en' => '{{ new_member.name }} registered your {{ op_term.community }}, {{ community.name }}',
            'ja' => '{{ new_member.name }} さんがあなたの{{ op_term.community }}に参加しました',
        ],
        'registration-complete' => [
            'en' => 'Information of the completion of registration',
            'ja' => '登録完了のお知らせ',
        ],
        'withdrawal-complete' => [
            'en' => 'Your leaving process was finished',
            'ja' => '退会手続きが完了しました',
        ],
        'withdrawal-admin-notice' => [
            'en' => 'A member has withdrawn from {{ op_config.sns_name }}',
            'ja' => '{{ op_config.sns_name }} からメンバーが退会しました',
        ],
        'password-changed' => [
            'en' => 'Your password was changed',
            'ja' => 'パスワードが変更されました',
        ],
        'mfa-enabled' => [
            'en' => 'Two-factor authentication was enabled',
            'ja' => '2要素認証が有効になりました',
        ],
        'mfa-disabled' => [
            'en' => 'Two-factor authentication was disabled',
            'ja' => '2要素認証が無効になりました',
        ],
        'mfa-reset-link' => [
            'en' => 'Reset your two-factor authentication',
            'ja' => '2要素認証のリセット',
        ],
        'signature' => [
            'en' => null,
            'ja' => null,
        ],
    ];

    /**
     * @return array<string, array{ja: array{subject: ?string, body: string}, en: array{subject: ?string, body: string}}>
     */
    public static function all(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $out = [];
        foreach (self::SUBJECTS as $key => $subjects) {
            $out[$key] = [
                'en' => ['subject' => $subjects['en'], 'body' => self::body($key, 'en')],
                'ja' => ['subject' => $subjects['ja'], 'body' => self::body($key, 'ja')],
            ];
        }

        return $cache = $out;
    }

    private static function body(string $key, string $locale): string
    {
        // Resolved relative to the package root rather than via resource_path(), so the registry works
        // without booting the framework.
        $path = dirname(__DIR__, 3)."/resources/mail-templates/{$locale}/{$key}.twig";

        // Only the file's single trailing newline is trimmed; interior blank lines are part of the wording.
        return rtrim((string) file_get_contents($path), "\n");
    }
}

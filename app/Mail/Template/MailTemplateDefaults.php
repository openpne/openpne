<?php

declare(strict_types=1);

namespace App\Mail\Template;

/**
 * Built-in default subject/body per template, per locale, in the renderer dialect — the fallback used
 * when no `mail_template_translations` row exists, so a fresh install (and any template an admin has not
 * edited) sends exactly this wording. Subjects are single lines kept here; bodies are read verbatim from
 * `resources/mail-templates/{locale}/{key}.txt` so the OpenPNE 3 text stays byte-exact (imported
 * templates and these defaults share one dialect). Subject is null only for the non-sendable signature.
 *
 * Templates with an OpenPNE 3 origin carry OpenPNE 3's `sample:` text verbatim
 * (`OpenPNE3/lib/config/config/mail_template.yml`); OpenPNE-4-only templates are authored here.
 */
final class MailTemplateDefaults
{
    /** @var array<string, array{en: ?string, ja: ?string}> per-key single-line subject (null = no subject). */
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
        'message-received' => [
            'en' => 'You have a new message',
            'ja' => '新しいメッセージが届きました',
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
        // Resolved relative to the package root (app/Mail/Template → base) rather than via resource_path()
        // so the registry resolves without booting the framework. Trim only the file's single trailing
        // newline; interior blank lines are part of the wording.
        $path = dirname(__DIR__, 3)."/resources/mail-templates/{$locale}/{$key}.txt";

        return rtrim((string) file_get_contents($path), "\n");
    }
}

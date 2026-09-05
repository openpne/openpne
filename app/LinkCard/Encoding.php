<?php

declare(strict_types=1);

namespace App\LinkCard;

/**
 * Converts a fetched page to UTF-8 before the HTML5 parser, which assumes it, reads anything; a
 * wrong guess stores a title of mojibake rather than failing. The order in `toUtf8()` is the design
 * and is explained step by step in docs/internals/link-cards.md, "Encoding is not an edge case".
 */
final class Encoding
{
    /**
     * Charset labels that appear in the wild and are not what mbstring calls them.
     *
     * `shift_jis` in a Japanese page's meta tag almost always means the CP932 superset — the encoding
     * Windows editors actually produce — so mapping it to strict Shift_JIS drops the vendor
     * characters that are the whole reason those pages differ.
     */
    private const ALIASES = [
        'shift_jis' => 'SJIS-win',
        'shift-jis' => 'SJIS-win',
        'sjis' => 'SJIS-win',
        'x-sjis' => 'SJIS-win',
        'windows-31j' => 'SJIS-win',
        'ms932' => 'SJIS-win',
        'cp932' => 'SJIS-win',
        'euc-jp' => 'eucJP-win',
        'eucjp' => 'eucJP-win',
        'x-euc-jp' => 'eucJP-win',
        'iso-2022-jp' => 'ISO-2022-JP',
        'csiso2022jp' => 'ISO-2022-JP',
        'utf8' => 'UTF-8',
    ];

    /** Tried in order when nothing is declared, or when the declared charset does not fit the bytes. */
    private const DETECTION_ORDER = ['UTF-8', 'SJIS-win', 'eucJP-win', 'ISO-2022-JP'];

    /** How far into the document to look for a declared charset when the response header carried none. */
    private const SNIFF_BYTES = 4096;

    public static function toUtf8(string $html, ?string $declared): string
    {
        $charset = self::resolve($declared ?? self::sniff($html));

        // Before any UTF-8 test, which ISO-2022-JP passes by hiding inside ASCII; the condition is
        // declaration plus a designation sequence, not validity, because a truncated body is invalid
        // yet still ISO-2022-JP and a declaration with no escapes is a mislabel.
        if ($charset === 'ISO-2022-JP' && self::hasIso2022Escape($html)) {
            return mb_convert_encoding($html, 'UTF-8', 'ISO-2022-JP');
        }

        if (mb_check_encoding($html, 'UTF-8')) {
            return $html;
        }

        if ($charset !== null && $charset !== 'UTF-8' && mb_check_encoding($html, $charset)) {
            return mb_convert_encoding($html, 'UTF-8', $charset);
        }

        $detected = mb_detect_encoding($html, self::DETECTION_ORDER, true);

        if ($detected !== false) {
            return mb_convert_encoding($html, 'UTF-8', $detected);
        }

        // Valid in nothing, almost always a body cut mid-character by the read cap: converting from
        // the declared charset substitutes only the broken tail, where UTF-8-from-UTF-8 would mangle
        // every multi-byte character before it.
        return mb_convert_encoding($html, 'UTF-8', $charset ?? 'UTF-8');
    }

    /**
     * Whether the body carries an ISO-2022-JP character-set designation.
     *
     * `ESC $ B` / `ESC $ @` (JIS X 0208) and `ESC ( J` (JIS X 0201 Roman) are the sequences that
     * switch into a non-ASCII set — their presence is what makes the bytes something other than the
     * plain ASCII they otherwise look like.
     */
    private static function hasIso2022Escape(string $html): bool
    {
        // Single-quoted after the escape byte: in a double-quoted string `$B` would interpolate as a
        // variable, leaving a bare ESC as the needle — which matches any escape sequence at all.
        return str_contains($html, "\x1B".'$B')
            || str_contains($html, "\x1B".'$@')
            || str_contains($html, "\x1B".'(J');
    }

    /** The mbstring name for a charset label, or null when mbstring does not know it. */
    private static function resolve(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        $label = strtolower(trim($label, " \t\n\r\0\x0B\"'"));

        if ($label === '') {
            return null;
        }

        if (isset(self::ALIASES[$label])) {
            return self::ALIASES[$label];
        }

        // mb_convert_encoding() raises on an unknown name, so an exotic label is turned into "no
        // declaration" and left to detection.
        $known = array_map('strtolower', mb_list_encodings());

        return in_array($label, $known, true) ? $label : null;
    }

    /**
     * Both spellings are matched: the HTML5 `<meta charset>` and the older
     * `<meta http-equiv="Content-Type" content="text/html; charset=...">`, which is what the legacy
     * pages this exists for carry. Only the head is examined, since HTML requires the declaration
     * near the top and scanning a whole document for something shaped like a charset invites
     * matching body text.
     */
    private static function sniff(string $html): ?string
    {
        $head = substr($html, 0, self::SNIFF_BYTES);

        if (preg_match('/<meta[^>]*?\bcharset\s*=\s*["\']?\s*([a-z0-9_\-]+)/i', $head, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}

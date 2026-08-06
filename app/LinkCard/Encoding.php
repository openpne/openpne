<?php

declare(strict_types=1);

namespace App\LinkCard;

/**
 * Converts a fetched page to UTF-8 before anything tries to read it.
 *
 * Not an edge case for a Japanese SNS: Shift_JIS, EUC-JP and ISO-2022-JP pages are still out there in
 * quantity, and the parser this feeds (Masterminds\HTML5) assumes UTF-8. Skipping this step does not
 * fail loudly — it produces a card whose title is mojibake, which then gets stored and shown.
 *
 * The order below is the whole design, and each step is there because the obvious alternative is
 * wrong in a specific way:
 *
 *  1. **ISO-2022-JP first, if that is what was declared.** It encodes Japanese entirely within the
 *     ASCII byte range using escape sequences, so it passes a UTF-8 validity check — leaving a title
 *     full of raw `ESC $ B` sequences. It is the one legacy encoding a UTF-8 test cannot rule out.
 *  2. **Then strict UTF-8.** Asking instead whether the *declared* charset fits the bytes does not
 *     work: almost any UTF-8 Japanese text is also a valid CP932 sequence, so a page mislabelled
 *     Shift_JIS by a CMS template would answer yes and be converted into mojibake. UTF-8's structure
 *     is strict, and Shift_JIS lead bytes are invalid in it, so a genuine legacy page fails here.
 *  3. **Then the declaration**, then detection.
 *  4. **Then the declaration again, with substitution.** A body cut mid-character by the read cap is
 *     valid in nothing, and a blanket `UTF-8 from UTF-8` at that point replaces every multi-byte
 *     character in the whole prefix (`日本語` becomes `???{??`). Converting from the charset the page
 *     declared replaces only the incomplete tail and keeps the rest readable.
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

        // Escape-sequence encodings hide inside ASCII, so they have to be handled before any UTF-8
        // test can be trusted. See the class docblock.
        if ($charset === 'ISO-2022-JP' && mb_check_encoding($html, 'ISO-2022-JP')) {
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

        // Valid in nothing — almost always a body the read cap cut mid-character. Converting from the
        // declared charset substitutes just the broken tail; falling back to UTF-8-from-UTF-8 here
        // would mangle every multi-byte character before it too.
        return mb_convert_encoding($html, 'UTF-8', $charset ?? 'UTF-8');
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
     * The charset a document declares in its own markup, in either spelling.
     *
     * Both forms are matched: the HTML5 `<meta charset>` and the older
     * `<meta http-equiv="Content-Type" content="text/html; charset=...">`, which is what the legacy
     * pages this exists for actually carry. Only the head is examined — the declaration is required
     * near the top, and scanning a whole document for something shaped like a charset invites
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

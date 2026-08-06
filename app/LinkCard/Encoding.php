<?php

declare(strict_types=1);

namespace App\LinkCard;

/**
 * Converts a fetched page to UTF-8 before anything tries to read it.
 *
 * Not an edge case for a Japanese SNS: Shift_JIS and EUC-JP pages are still out there in quantity,
 * and the parser this feeds (Masterminds\HTML5) assumes UTF-8. Skipping this step does not fail
 * loudly — it produces a card whose title is mojibake, which then gets stored and shown.
 *
 * The declaration is not taken at its word. A page that says Shift_JIS and is actually UTF-8 is
 * common enough — a CMS default left in a template — to be worth handling, and "does the declared
 * charset fit these bytes?" cannot tell the two apart: almost any UTF-8 Japanese text is *also* a
 * valid CP932 byte sequence, so that check says yes and the page turns to mojibake.
 *
 * Valid UTF-8 is the discriminating signal, so it is tested first. The structure is strict — a lead
 * byte in 0xC2–0xF4 followed by continuation bytes in 0x80–0xBF — while Shift_JIS puts its lead bytes
 * in ranges UTF-8 does not accept at all (0x93 0xFA, 日, is not valid UTF-8 in any position). Real
 * legacy pages therefore fail the UTF-8 check immediately and fall through to their declaration; the
 * only text valid in both is plain ASCII, where the conversion is the identity anyway.
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
        'x-sjis' => 'SJIS-win',
        'windows-31j' => 'SJIS-win',
        'cp932' => 'SJIS-win',
        'euc-jp' => 'eucJP-win',
        'x-euc-jp' => 'eucJP-win',
        'iso-2022-jp' => 'ISO-2022-JP',
        'utf8' => 'UTF-8',
    ];

    /** Tried in order when nothing is declared, or when the declared charset does not fit the bytes. */
    private const DETECTION_ORDER = ['UTF-8', 'SJIS-win', 'eucJP-win', 'ISO-2022-JP'];

    /** How far into the document to look for a <meta> charset when the response header carried none. */
    private const SNIFF_BYTES = 4096;

    public static function toUtf8(string $html, ?string $declared): string
    {
        // Checked before the declaration, not after: see the class docblock for why a mis-declared
        // page cannot be detected the other way round.
        if (mb_check_encoding($html, 'UTF-8')) {
            return $html;
        }

        $charset = self::resolve($declared ?? self::sniff($html));

        if ($charset !== null && $charset !== 'UTF-8' && mb_check_encoding($html, $charset)) {
            return mb_convert_encoding($html, 'UTF-8', $charset);
        }

        $detected = mb_detect_encoding($html, self::DETECTION_ORDER, true);

        return $detected === false
            // Not valid in anything tried — most likely truncated mid-character by the read cap.
            // Substituting the bad bytes beats handing the parser something it will choke on.
            ? mb_convert_encoding($html, 'UTF-8', 'UTF-8')
            : mb_convert_encoding($html, 'UTF-8', $detected);
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
     * The charset a document declares in its own markup.
     *
     * Only the head is examined: the declaration is required to be near the top, and scanning a
     * whole document for something shaped like a charset invites matching body text.
     */
    private static function sniff(string $html): ?string
    {
        $head = substr($html, 0, self::SNIFF_BYTES);

        if (preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([a-z0-9_\-]+)/i', $head, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}

/**
 * Splits a plain-text body into text and bare-URL segments, mirroring the Classic
 * App\Support\BodyText (app/Support/BodyText.php). The URL regex, the www.→http:// href rule, and
 * the 57-char visible truncation are kept in lockstep with that class; the shared cases are pinned
 * by tests/Unit/Support/BodyTextTest.php (PHP) and linkify.test.ts (this file's sibling).
 *
 * Escaping is the renderer's job: <UserText> emits both the visible text and the href through React,
 * which escapes them, so this module returns raw strings and never HTML.
 */
export type Segment = { type: 'text'; value: string } | { type: 'url'; href: string; visible: string };

/** Bare http(s):// or www. URL, ending before whitespace, a tag/quote char, or trailing punctuation. */
const URL = /(\b(?:https?:\/\/|www\.)[^\s<>"]+[^\s<>".,;:!?)\]])/giu;

/** BodyText::VISIBLE_URL_LIMIT. */
const VISIBLE_URL_LIMIT = 57;

export function linkify(text: string | null | undefined): Segment[] {
    // String.split with a captured delimiter yields [text, url, text, url, …] — URLs land on the odd
    // indices, matching PHP's PREG_SPLIT_DELIM_CAPTURE.
    return String(text ?? '')
        .split(URL)
        .map((part, i): Segment => {
            if (i % 2 === 0) {
                return { type: 'text', value: part };
            }

            const href = part.toLowerCase().startsWith('www.') ? `http://${part}` : part;
            // Char-count truncation. BodyText uses Str::limit, whose threshold is display width
            // (mb_strwidth); for ASCII URLs — the real-world case — the two agree. Full-width URLs are
            // a documented, intentional divergence (see linkify.test.ts).
            const visible = part.length > VISIBLE_URL_LIMIT ? `${part.slice(0, VISIBLE_URL_LIMIT)}...` : part;

            return { type: 'url', href, visible };
        });
}

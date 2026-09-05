/**
 * The URL regex, the www.→http:// href rule and the 57-char visible truncation are kept in lockstep
 * with App\Support\BodyText, with the shared cases pinned on both sides (docs/internals/body-text.md,
 * "Render authority is the server").
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
            // BodyText truncates on display width (`Str::limit`), which agrees with a char count for
            // ASCII URLs; full-width URLs are an intentional divergence.
            const visible = part.length > VISIBLE_URL_LIMIT ? `${part.slice(0, VISIBLE_URL_LIMIT)}...` : part;

            return { type: 'url', href, visible };
        });
}

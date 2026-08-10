/**
 * Code-point counting for the compose forms. A body's cap and a mention's stored range are both
 * counted in Unicode code points (docs/internals/timeline.md), while the DOM reports caret and
 * selection offsets in UTF-16 code units — so a picker converts between the two, in one place.
 *
 * `Array.from` is the same split entity-split.ts renders with, so a range written here and read
 * there cannot disagree about where an astral emoji ends.
 */

/** Code points in `text`. `text.length` counts an astral emoji as two. */
export function codePointLength(text: string): number {
    return Array.from(text).length;
}

/** The code-point offset that a UTF-16 offset into `text` names. */
export function utf16ToCodePoint(text: string, utf16Offset: number): number {
    return codePointLength(text.slice(0, utf16Offset));
}

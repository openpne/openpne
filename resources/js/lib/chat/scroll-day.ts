/**
 * Which row owns the top of the reading area: the first whose foot is below the line.
 *
 * Rows are handed in as a count and an accessor rather than an array of rectangles, because building
 * that array is the cost this exists to avoid. A conversation's list is never trimmed
 * (lib/chat/stream-state keeps every row it has ever merged), so six presses of "load older" is 350
 * rows and ten is 550; measuring all of them on every frame of a scroll is 20,000 layout reads a
 * second. Nine reads answer the same question.
 *
 * The search leans on the list being monotonic — rows are in DOM order, which is chronological, which
 * is top to bottom — and that is the one thing a caller must not break. An IntersectionObserver on the
 * date headings would make the per-frame cost zero and would watch days rather than rows, but it needs
 * a seed for the first paint and more moving parts than this; the trade was looked at and this side
 * chosen, so the next reader does not have to look again.
 *
 * Null when every row's foot is above the line — the reader is past the end of what is loaded.
 */
export function rowAtLine(count: number, footOf: (index: number) => number, line: number): number | null {
    let low = 0;
    let high = count;

    while (low < high) {
        const middle = (low + high) >> 1;

        if (footOf(middle) > line) {
            high = middle;
        } else {
            low = middle + 1;
        }
    }

    return low < count ? low : null;
}

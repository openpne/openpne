/**
 * Which row owns the top of the reading area: the first whose foot is below the line, or null when
 * every row's foot is above it. The search leans on the rows being monotonic — DOM order is
 * chronological — which is the one thing a caller must not break.
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

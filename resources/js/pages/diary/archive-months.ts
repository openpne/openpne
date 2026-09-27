import { type Bucket, buildMonthRows, type MonthlyCount, type YearRow } from '../../lib/month-grid.ts';

export { selectedBeyondRecentYears } from '../../lib/month-grid.ts';
export type { MonthlyCount } from '../../lib/month-grid.ts';

export function withKeyword(path: string, keyword?: string): string {
    if (!keyword) {
        return path;
    }

    return `${path}?${new URLSearchParams({ keyword }).toString()}`;
}

export function countBucket(count: number): Bucket {
    if (count <= 0) return 0;
    if (count <= 2) return 1;
    if (count <= 5) return 2;
    if (count <= 9) return 3;
    return 4;
}

/** A keyword can leave the month the reader is on without matches, which is what `selected` is for. */
export function buildArchiveGrid(
    counts: MonthlyCount[],
    currentYear: number,
    ownerId: number,
    keyword?: string,
    selected: { year: number } | null = null,
): YearRow[] {
    return buildMonthRows(
        counts,
        currentYear,
        (year, month) => withKeyword(`/diary/listMember/${ownerId}/${year}/${month}`, keyword),
        selected,
    );
}

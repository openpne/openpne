export interface MonthlyCount {
    year: number;
    month: number; // 1-12
    count: number;
}

export interface MonthCell {
    month: number; // 1-12
    count: number;
    href: string | null; // null for an empty month (a non-linked cell)
}

export interface YearRow {
    year: number;
    months: MonthCell[]; // always 12, January..December
}

export type Bucket = 0 | 1 | 2 | 3 | 4;

/**
 * Whether the selected month sits in a year beyond the always-visible recent rows — the grid must
 * then start expanded, or a navigation to an older month would hide its own selection ring.
 */
export function selectedBeyondRecentYears(rows: YearRow[], selected: { year: number } | null, recentYears: number): boolean {
    if (selected === null) {
        return false;
    }

    return rows.slice(recentYears).some((row) => row.year === selected.year);
}

/**
 * Empty counts give `[]`, which hides the grid; a month with a count links where `href` says and
 * every other cell is non-linked. `currentYear` is a parameter rather than read from `Date` here so
 * the expansion stays pure.
 */
export function buildMonthRows(
    counts: MonthlyCount[],
    currentYear: number,
    href: (year: number, month: number) => string,
    selected: { year: number } | null = null,
): YearRow[] {
    if (counts.length === 0) {
        return [];
    }

    const byYearMonth = new Map<string, number>();
    let minYear = currentYear;
    let maxYear = currentYear;
    for (const { year, month, count } of counts) {
        byYearMonth.set(`${year}-${month}`, count);
        if (year < minYear) minYear = year;
        if (year > maxYear) maxYear = year;
    }
    // The month the reader is on may hold no count; keep its year in range anyway so the selection
    // ring (their current position) never drops off the map.
    if (selected) {
        if (selected.year < minYear) minYear = selected.year;
        if (selected.year > maxYear) maxYear = selected.year;
    }

    const rows: YearRow[] = [];
    for (let year = maxYear; year >= minYear; year--) {
        const months: MonthCell[] = [];
        for (let month = 1; month <= 12; month++) {
            const count = byYearMonth.get(`${year}-${month}`) ?? 0;
            months.push({ month, count, href: count > 0 ? href(year, month) : null });
        }
        rows.push({ year, months });
    }

    return rows;
}

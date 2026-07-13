export interface MonthlyCount {
    year: number;
    month: number; // 1-12
    count: number;
}

export interface ArchiveMonthCell {
    month: number; // 1-12
    count: number;
    href: string | null; // null for an empty month (a non-linked cell)
}

export interface ArchiveYearRow {
    year: number;
    months: ArchiveMonthCell[]; // always 12, January..December
}

/** Fixed heat buckets for the cell shading: 0 / 1-2 / 3-5 / 6-9 / 10+. */
export function countBucket(count: number): 0 | 1 | 2 | 3 | 4 {
    if (count <= 0) return 0;
    if (count <= 2) return 1;
    if (count <= 5) return 2;
    if (count <= 9) return 3;
    return 4;
}

/**
 * Expand sparse monthly counts into a year-descending grid: every year from the earliest counted
 * year through max(currentYear, latest counted year) — so the current year is always shown — each
 * zero-filled to 12 month cells. A month with entries links to its archive; an empty month (incl.
 * the current year's not-yet-reached months) is a non-linked cell. Empty counts → [] (grid hidden).
 *
 * `currentYear` is passed in rather than read from Date here so the expansion stays pure/testable.
 */
export function buildArchiveGrid(counts: MonthlyCount[], currentYear: number, ownerId: number): ArchiveYearRow[] {
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

    const rows: ArchiveYearRow[] = [];
    for (let year = maxYear; year >= minYear; year--) {
        const months: ArchiveMonthCell[] = [];
        for (let month = 1; month <= 12; month++) {
            const count = byYearMonth.get(`${year}-${month}`) ?? 0;
            months.push({ month, count, href: count > 0 ? `/diary/listMember/${ownerId}/${year}/${month}` : null });
        }
        rows.push({ year, months });
    }

    return rows;
}

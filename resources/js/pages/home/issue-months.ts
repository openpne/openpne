import type { Bucket } from '../../lib/month-grid.ts';

/** The padded form, which is the one the server links (docs/internals/home-issues.md, "Routes"). */
export function monthHref(year: number, month: number): string {
    return `/home/${year}/${String(month).padStart(2, '0')}`;
}

/** By the week: a month holds at most 31 issues, so the diary's thresholds would fill every cell. */
export function issueBucket(count: number): Bucket {
    if (count <= 0) return 0;
    if (count <= 7) return 1;
    if (count <= 15) return 2;
    if (count <= 23) return 3;
    return 4;
}

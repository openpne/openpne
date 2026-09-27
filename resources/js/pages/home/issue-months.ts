import type { Bucket, MonthlyCount } from '../../lib/month-grid.ts';

/** A month as the server lists it, with the URL the server links it by. */
export interface IssueMonth extends MonthlyCount {
    href: string;
}

/** Only a listed month is ever asked for: a month with no count is not a link. */
export function listedHref(months: IssueMonth[]): (year: number, month: number) => string {
    const hrefs = new Map(months.map((listed) => [`${listed.year}-${listed.month}`, listed.href]));

    return (year, month) => hrefs.get(`${year}-${month}`) ?? '';
}

/** By the week: a month holds at most 31 issues, so the diary's thresholds would fill every cell. */
export function issueBucket(count: number): Bucket {
    if (count <= 0) return 0;
    if (count <= 7) return 1;
    if (count <= 15) return 2;
    if (count <= 23) return 3;
    return 4;
}

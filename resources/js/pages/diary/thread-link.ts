/** Mirrors the Classic pager URL: size always, order dropped when default (desc), page when 1. */
export function diaryThreadLink(diaryId: number, size: number, page: number, ascending: boolean): string {
    const params = new URLSearchParams({ size: String(size) });
    if (ascending) params.set('order', 'asc');
    if (page > 1) params.set('page', String(page));
    return `/diary/${diaryId}?${params.toString()}`;
}

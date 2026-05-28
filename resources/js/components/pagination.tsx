import { Link, usePage } from '@inertiajs/react';

export interface PaginationMeta {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
}

interface PaginationProps {
    meta: PaginationMeta;
    pageName?: string;
}

export function Pagination({ meta, pageName = 'page' }: PaginationProps) {
    const { url } = usePage();
    if (meta.lastPage <= 1) {
        return null;
    }

    const linkTo = (page: number): string => {
        const [path, query = ''] = url.split('?');
        const params = new URLSearchParams(query);
        params.set(pageName, String(page));
        return `${path}?${params.toString()}`;
    };

    return (
        <nav aria-label="Pagination" className="flex items-center gap-3 text-sm">
            {meta.currentPage > 1 ? (
                <Link href={linkTo(meta.currentPage - 1)} preserveScroll>
                    Previous
                </Link>
            ) : (
                <span aria-disabled="true" className="text-muted-foreground">
                    Previous
                </span>
            )}
            <span>
                Page {meta.currentPage} of {meta.lastPage}
            </span>
            {meta.currentPage < meta.lastPage ? (
                <Link href={linkTo(meta.currentPage + 1)} preserveScroll>
                    Next
                </Link>
            ) : (
                <span aria-disabled="true" className="text-muted-foreground">
                    Next
                </span>
            )}
        </nav>
    );
}

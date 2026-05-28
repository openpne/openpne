import { Link, usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';

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
    const t = useT();
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

    const previous = t('Previous');
    const next = t('Next');

    return (
        <nav aria-label={t('Pagination Navigation')} className="flex items-center gap-3 text-sm">
            {meta.currentPage > 1 ? (
                <Link href={linkTo(meta.currentPage - 1)} preserveScroll>
                    {previous}
                </Link>
            ) : (
                <span aria-disabled="true" className="text-muted-foreground">
                    {previous}
                </span>
            )}
            <span>
                {t('Page :current of :last', { current: meta.currentPage, last: meta.lastPage })}
            </span>
            {meta.currentPage < meta.lastPage ? (
                <Link href={linkTo(meta.currentPage + 1)} preserveScroll>
                    {next}
                </Link>
            ) : (
                <span aria-disabled="true" className="text-muted-foreground">
                    {next}
                </span>
            )}
        </nav>
    );
}

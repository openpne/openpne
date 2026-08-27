import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { CivilDate } from '@/components/timestamp';
import { useT } from '@/lib/i18n';
import type { IssueRef } from './types';

/**
 * The way out of one issue: the day before it, the day after it, and the full run.
 *
 * Same pager grammar as a diary entry's older/newer pair, so the two read as one movement — the
 * direction is the chevron's, and what the reader is moving to is named under the label. The current
 * issue has nothing after it, so its right half is empty rather than disabled: there is no future
 * issue to say anything about.
 */
export function IssueNav({ prev, next }: { prev: IssueRef | null; next: IssueRef | null }) {
    const t = useT();

    return (
        <nav className="flex items-center justify-between gap-3" aria-label={t('Issue navigation')}>
            {prev ? (
                <Link href={prev.href} className="group flex min-h-11 min-w-0 flex-1 items-center gap-1.5">
                    <ChevronLeft className="size-4 shrink-0 text-link" aria-hidden />
                    <span className="min-w-0">
                        <span className="block text-xs text-muted-foreground">{t('Previous issue')}</span>
                        <span className="block truncate text-sm text-link group-hover:underline">
                            <CivilDate value={prev.date} weekday />
                        </span>
                    </span>
                </Link>
            ) : (
                <span className="flex-1" />
            )}

            <Link href="/home/issues" className="flex min-h-11 shrink-0 items-center text-sm text-link hover:underline">
                {t('Back issues')}
            </Link>

            {next ? (
                <Link href={next.href} className="group flex min-h-11 min-w-0 flex-1 items-center justify-end gap-1.5 text-right">
                    <span className="min-w-0">
                        <span className="block text-xs text-muted-foreground">{t('Next issue')}</span>
                        <span className="block truncate text-sm text-link group-hover:underline">
                            <CivilDate value={next.date} weekday />
                        </span>
                    </span>
                    <ChevronRight className="size-4 shrink-0 text-link" aria-hidden />
                </Link>
            ) : (
                <span className="flex-1" />
            )}
        </nav>
    );
}

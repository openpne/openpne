import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Heading } from '@/components/ui/heading';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';
import type { MonthRef } from './types';

/** A side with no month to go to is empty rather than disabled, as the day pager's is. */
export function MonthNav({ month, prev, next }: { month: MonthRef; prev: MonthRef | null; next: MonthRef | null }) {
    const t = useT();
    const { civilMonth } = useDateFormat();

    return (
        <nav className="flex items-center justify-between gap-3" aria-label={t('Month navigation')}>
            {prev ? (
                <Link href={prev.href} className="group flex min-h-11 min-w-0 flex-1 items-center gap-1.5">
                    <ChevronLeft className="size-4 shrink-0 text-link" aria-hidden />
                    <span className="min-w-0">
                        <span className="block text-xs text-muted-foreground">{t('Earlier month')}</span>
                        <span className="block text-sm text-link group-hover:underline">{civilMonth(prev.year, prev.month)}</span>
                    </span>
                </Link>
            ) : (
                <span className="flex-1" />
            )}

            <Heading as="h2" variant="group" className="shrink-0">
                {civilMonth(month.year, month.month)}
            </Heading>

            {next ? (
                <Link href={next.href} className="group flex min-h-11 min-w-0 flex-1 items-center justify-end gap-1.5 text-right">
                    <span className="min-w-0">
                        <span className="block text-xs text-muted-foreground">{t('Later month')}</span>
                        <span className="block text-sm text-link group-hover:underline">{civilMonth(next.year, next.month)}</span>
                    </span>
                    <ChevronRight className="size-4 shrink-0 text-link" aria-hidden />
                </Link>
            ) : (
                <span className="flex-1" />
            )}
        </nav>
    );
}

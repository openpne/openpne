import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';
import type { MonthRef } from './types';

interface Props {
    month: MonthRef;
    prev: MonthRef | null;
    next: MonthRef | null;
    /** The month's own name, between the two. */
    children: ReactNode;
}

/** A side with no month to go to is empty rather than disabled, as the day pager's is. */
export function MonthNav({ month, prev, next, children }: Props) {
    const t = useT();
    const { civilMonth, civilMonthShort } = useDateFormat();

    // The year is said only when it is another one: beside the month's name the row has no room to repeat it.
    const named = (other: MonthRef): string => (other.year === month.year ? civilMonthShort(other.month) : civilMonth(other.year, other.month));

    return (
        <nav className="flex items-center justify-between gap-3" aria-label={t('Month navigation')}>
            {prev ? (
                <Link href={prev.href} className="group flex min-h-11 min-w-0 flex-1 items-center gap-1.5">
                    <ChevronLeft className="size-4 shrink-0 text-link" aria-hidden />
                    <span className="min-w-0">
                        <span className="block text-xs text-muted-foreground">{t('Earlier month')}</span>
                        <span className="block text-sm text-link group-hover:underline">{named(prev)}</span>
                    </span>
                </Link>
            ) : (
                <span className="flex-1" />
            )}

            {children}

            {next ? (
                <Link href={next.href} className="group flex min-h-11 min-w-0 flex-1 items-center justify-end gap-1.5 text-right">
                    <span className="min-w-0">
                        <span className="block text-xs text-muted-foreground">{t('Later month')}</span>
                        <span className="block text-sm text-link group-hover:underline">{named(next)}</span>
                    </span>
                    <ChevronRight className="size-4 shrink-0 text-link" aria-hidden />
                </Link>
            ) : (
                <span className="flex-1" />
            )}
        </nav>
    );
}

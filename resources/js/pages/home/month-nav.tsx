import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';
import { PagerStep } from './issue-nav';
import type { MonthRef } from './types';

/** The year is said only when it is another one: beside the month's name a row has no room to repeat it. */
export function useMonthNames(month: MonthRef): (other: MonthRef) => string {
    const { civilMonth, civilMonthShort } = useDateFormat();

    return (other) => (other.year === month.year ? civilMonthShort(other.month) : civilMonth(other.year, other.month));
}

/** A side with no month to go to is empty rather than disabled, as the day pager's is. */
export function MonthPager({ month, prev, next }: { month: MonthRef; prev: MonthRef | null; next: MonthRef | null }) {
    const t = useT();
    const named = useMonthNames(month);

    return (
        <nav className="flex items-center justify-between gap-2" aria-label={t('Month navigation')}>
            <div>
                {prev && (
                    <PagerStep href={prev.href} caption={t('Earlier month')} side="prev">
                        {named(prev)}
                    </PagerStep>
                )}
            </div>
            <div>
                {next && (
                    <PagerStep href={next.href} caption={t('Later month')} side="next">
                        {named(next)}
                    </PagerStep>
                )}
            </div>
        </nav>
    );
}

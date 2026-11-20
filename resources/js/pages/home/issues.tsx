import { Head, usePage } from '@inertiajs/react';
import { useT } from '@/lib/i18n';
import { DayBlock } from './day-block';
import { DoubleRule } from './masthead';
import { MonthCalendar } from './month-calendar';
import { MonthPager } from './month-nav';
import { MonthBand } from './month-band';
import type { IssuesPageProps } from './types';

/** One month of days, newest first (docs/internals/home-issues.md, "The month page"). */
export default function HomeIssues() {
    const t = useT();
    const { month, prev, next, days, months } = usePage<IssuesPageProps>().props;

    if (month === null) {
        return (
            <>
                <Head title={t('What happened')} />
                <p className="text-sm text-muted-foreground">{t('Nothing yet.')}</p>
            </>
        );
    }

    return (
        <>
            <Head title={t('What happened')} />
            <div className="flex flex-col">
                <MonthBand month={month} prev={prev} next={next} months={months} />
                <div className="mt-3.5">
                    <MonthCalendar month={month} days={days} />
                </div>
                {days.length === 0 ? (
                    <p className="mt-6 text-sm text-muted-foreground">{t('Nothing happened this month.')}</p>
                ) : (
                    <ul className="mt-6">
                        {days.map((day) => (
                            <DayBlock key={day.date} day={day} />
                        ))}
                    </ul>
                )}
                <div className="mt-6">
                    <DoubleRule heavyFirst={false} />
                </div>
                <MonthPager month={month} prev={prev} next={next} />
            </div>
        </>
    );
}

import { Head, usePage } from '@inertiajs/react';
import { MonthGrid } from '@/components/month-grid';
import { List, Panel } from '@/components/ui/surface';
import { daysPhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { buildMonthRows } from '@/lib/month-grid';
import { useDateFormat } from '@/lib/use-date-format';
import { DayCard } from './day-card';
import { HeatCalendar } from './heat-calendar';
import { issueBucket, monthHref } from './issue-months';
import { MonthNav } from './month-nav';
import type { IssuesPageProps } from './types';

/** One month of days, newest first (docs/internals/home-issues.md, "The month page"). */
export default function HomeIssues() {
    const t = useT();
    const date = useDateFormat();
    const { month, prev, next, days, months } = usePage<IssuesPageProps>().props;

    if (month === null) {
        return (
            <>
                <Head title={t('Past happenings')} />
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('Nothing yet.')}</p>
                </Panel>
            </>
        );
    }

    return (
        <>
            <Head title={t('Past happenings')} />
            <MonthNav month={month} prev={prev} next={next} />
            <HeatCalendar month={month} days={days} />

            {days.length === 0 ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('Nothing happened this month.')}</p>
                </Panel>
            ) : (
                <Panel flush>
                    <List>
                        {days.map((day) => (
                            <DayCard key={day.date} day={day} />
                        ))}
                    </List>
                </Panel>
            )}

            <MonthGrid
                title={t('Jump to a month')}
                rows={buildMonthRows(months, date.currentYear(), monthHref, month)}
                selected={month}
                bucket={issueBucket}
                countPhrase={(count) => daysPhrase(t, count)}
            />
        </>
    );
}

import { Head, usePage } from '@inertiajs/react';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { DayCard } from './day-card';
import { MonthNav } from './month-nav';
import type { IssuesPageProps } from './types';

/** One month of days, newest first (docs/internals/home-issues.md, "The month page"). */
export default function HomeIssues() {
    const t = useT();
    const { month, prev, next, days } = usePage<IssuesPageProps>().props;

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
        </>
    );
}

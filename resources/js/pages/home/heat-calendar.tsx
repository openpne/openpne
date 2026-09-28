import { Link } from '@inertiajs/react';
import { Panel } from '@/components/ui/surface';
import { monthWeeks } from '@/lib/date';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';
import { cn } from '@/lib/utils';
import { breakdown, daysCovered } from './day-card';
import type { DaySummary, MonthRef } from './types';

// Indexed by level, so level 0 takes no fill.
const FILL = ['', 'bg-selected/10', 'bg-selected/20', 'bg-selected/30', 'bg-selected/45'] as const;

const CELL = 'flex min-h-11 items-center justify-center rounded text-sm';

const WEEKDAYS = [0, 1, 2, 3, 4, 5, 6] as const;

/**
 * A day with an issue is a bordered link and a day without one is bare text, so the two are told
 * apart without the fill; the fill is said in words by the day's row below
 * (docs/internals/home-issues.md, "The month page").
 */
export function HeatCalendar({ month, days }: { month: MonthRef; days: DaySummary[] }) {
    const t = useT();
    const date = useDateFormat();

    // By `date` alone: an issue stands on the day it is dated, whatever its window reaches back over.
    const issues = new Map(days.map((day) => [Number(day.date.slice(8, 10)), day]));

    return (
        <Panel>
            <table className="w-full table-fixed border-separate border-spacing-1">
                <caption className="sr-only">{date.civilMonth(month.year, month.month)}</caption>
                <thead>
                    <tr>
                        {WEEKDAYS.map((weekday) => (
                            <th
                                key={weekday}
                                scope="col"
                                abbr={date.weekday(weekday, 'long')}
                                className="pb-1 text-center text-xs font-normal text-muted-foreground"
                            >
                                {date.weekday(weekday)}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {monthWeeks(month.year, month.month).map((week) => (
                        <tr key={week.find((day) => day !== null)}>
                            {week.map((number, weekday) => (
                                <td key={weekday} className="p-0">
                                    {number !== null && <Day number={number} issue={issues.get(number)} />}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>

            <p className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                <span>{t('How busy each day was, within this month')}</span>
                <span className="flex items-center gap-1">
                    <span>{t('Quieter days')}</span>
                    {FILL.slice(1).map((fill) => (
                        <span key={fill} aria-hidden className={cn('size-3 rounded-sm border border-border', fill)} />
                    ))}
                    <span>{t('Busier days')}</span>
                </span>
            </p>
        </Panel>
    );
}

function Day({ number, issue }: { number: number; issue: DaySummary | undefined }) {
    const t = useT();
    const { civilDate } = useDateFormat();

    if (issue === undefined) {
        return <span className={cn(CELL, 'text-muted-foreground')}>{number}</span>;
    }

    return (
        <Link
            href={issue.href}
            aria-label={[daysCovered(t, civilDate, issue), ...breakdown(t, issue.counts)].join(', ')}
            className={cn(CELL, 'border border-border text-foreground transition-colors hover:bg-selected/60', FILL[issue.level])}
        >
            {number}
        </Link>
    );
}

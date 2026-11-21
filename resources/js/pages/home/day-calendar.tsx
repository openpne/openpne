import { Link, usePage } from '@inertiajs/react';
import { Panel } from '@/components/ui/surface';
import { monthWeeks } from '@/lib/date';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';
import { useSiteDay } from '@/lib/use-site-day';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { IssueRef } from './types';

const WEEKDAYS = [0, 1, 2, 3, 4, 5, 6] as const;

const weekend = (weekday: number): string | undefined => (weekday === 0 ? 'text-sunday' : weekday === 6 ? 'text-saturday' : undefined);

/** The month of the day being read, each day with an issue a link to it (docs/internals/home-issues.md, "Rendering"). */
export function DayCalendar({ id, date, days, monthHref }: { id: string; date: string; days: IssueRef[]; monthHref: string }) {
    const t = useT();
    const format = useDateFormat();
    const { timezone } = usePage<PageProps>().props;
    useSiteDay(timezone);

    const year = Number(date.slice(0, 4));
    const month = Number(date.slice(5, 7));
    const prefix = date.slice(0, 8);
    const issues = new Map(days.map((day) => [day.date, day]));
    const today = format.siteDay(new Date().toISOString());
    const iso = (number: number): string => `${prefix}${String(number).padStart(2, '0')}`;
    // Named as a date is: the year only when it is not the site's current one.
    const monthName = year === format.currentYear() ? format.civilMonthShort(month) : format.civilMonth(year, month);

    return (
        <div id={id} data-day-calendar className="mx-auto w-full max-w-sm">
            <Panel>
                <table className="w-full table-fixed border-separate border-spacing-1">
                    <caption className="sr-only">{format.civilMonth(year, month)}</caption>
                    <thead>
                        <tr>
                            {WEEKDAYS.map((weekday) => (
                                <th
                                    key={weekday}
                                    scope="col"
                                    abbr={format.weekday(weekday, 'long')}
                                    className={cn('pb-0.5 text-center text-xs font-normal', weekend(weekday) ?? 'text-muted-foreground')}
                                >
                                    {format.weekday(weekday)}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {monthWeeks(year, month).map((week) => (
                            <tr key={week.find((day) => day !== null)}>
                                {week.map((number, weekday) => {
                                    if (number === null) {
                                        return <td key={weekday} />;
                                    }

                                    const day = iso(number);
                                    const issue = issues.get(day);
                                    const current = day === date;
                                    const isToday = day === today;
                                    const mark = (
                                        <span
                                            className={cn('flex size-7 items-center justify-center rounded-full text-sm', isToday && 'bg-foreground text-background')}
                                        >
                                            {number}
                                        </span>
                                    );

                                    return (
                                        <td key={weekday} className="p-0">
                                            {issue ? (
                                                <Link
                                                    href={issue.href}
                                                    aria-label={[format.civilMonthDay(day, true), isToday && t('Today')].filter(Boolean).join(', ')}
                                                    aria-current={current ? 'date' : undefined}
                                                    className={cn(
                                                        'flex h-10 items-center justify-center rounded bg-selected/15 transition-colors hover:bg-selected/40',
                                                        current && 'ring-2 ring-selected',
                                                    )}
                                                >
                                                    {mark}
                                                </Link>
                                            ) : (
                                                <span className="flex h-10 items-center justify-center text-muted-foreground">
                                                    {mark}
                                                    {isToday && <span className="sr-only">{t('Today')}</span>}
                                                </span>
                                            )}
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
                {/* Its words at the top of a full-height press that reaches down into the panel's padding and no further, so they sit right under the last week. */}
                <div className="-mt-1 -mb-4 flex justify-end">
                    <Link href={monthHref} className="flex min-h-11 items-start pt-1 text-sm text-link hover:underline">
                        {t(':month at a glance', { month: monthName })}
                    </Link>
                </div>
            </Panel>
        </div>
    );
}

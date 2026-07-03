import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { DiaryCalendarData } from './types';

// Sunday-first, matching the server grid (OpenPNE 3 Calendar_Month_Weekdays).
const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as const;

/**
 * The diary-list archive calendar (Classic x-diary.sidemenu parity): a month grid whose days with a
 * viewer-visible diary link to the day archive, framed by unbounded prev/next month nav. Rendered as
 * a real table so weekday headers associate with their column for assistive tech.
 */
export function DiaryCalendar({ calendar, ownerId }: { calendar: DiaryCalendarData; ownerId: number }) {
    const t = useT();
    const base = `/m/diary/listMember/${ownerId}`;
    const withDiary = new Set(calendar.diaryDays);
    const cell = 'inline-flex size-8 items-center justify-center rounded-md text-sm tabular-nums';
    const focus = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';
    const navButton = `inline-flex size-8 items-center justify-center rounded-md text-muted-foreground hover:bg-muted/40 hover:text-foreground ${focus}`;

    return (
        <Panel>
            <nav className="mb-2 flex items-center justify-between gap-2" aria-label={t('%Diary% calendar')}>
                <Link href={`${base}/${calendar.previousMonth.year}/${calendar.previousMonth.month}`} aria-label={t('Previous month')} className={navButton}>
                    <ChevronLeft className="size-4" aria-hidden />
                </Link>
                <span className="text-sm font-semibold tabular-nums text-foreground">{calendar.label}</span>
                <Link href={`${base}/${calendar.nextMonth.year}/${calendar.nextMonth.month}`} aria-label={t('Next month')} className={navButton}>
                    <ChevronRight className="size-4" aria-hidden />
                </Link>
            </nav>

            <table className="w-full table-fixed">
                <caption className="sr-only">{`${t('%Diary% calendar')} ${calendar.label}`}</caption>
                <thead>
                    <tr>
                        {WEEKDAYS.map((weekday) => (
                            <th key={weekday} scope="col" className="pb-1 text-center text-xs font-normal text-muted-foreground">
                                {t(weekday)}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {calendar.weeks.map((week, index) => (
                        // Weeks are a fixed positional grid, so the row index is a stable key.
                        <tr key={index}>
                            {week.map((day, dayIndex) => (
                                <td key={dayIndex} className="text-center">
                                    {day === null ? null : withDiary.has(day) ? (
                                        <Link href={`${base}/${calendar.year}/${calendar.month}/${day}`} className={cn(cell, focus, 'font-medium text-link hover:bg-muted/40')}>
                                            {day}
                                        </Link>
                                    ) : (
                                        <span className={cn(cell, 'text-muted-foreground')}>{day}</span>
                                    )}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </Panel>
    );
}

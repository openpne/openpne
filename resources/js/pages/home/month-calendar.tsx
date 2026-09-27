import { Link } from '@inertiajs/react';
import type { GridImage } from '@/components/image-grid';
import { monthWeeks } from '@/lib/date';
import { useT } from '@/lib/i18n';
import { markedName } from '@/lib/identity-mark';
import { useDateFormat } from '@/lib/use-date-format';
import { cn } from '@/lib/utils';
import { daysCovered, said } from './day-block';
import { StoryPicture } from './story';
import type { DaySummary, MonthRef } from './types';

type Translate = ReturnType<typeof useT>;

// Taller than wide on a phone, where a square has no room for a date over two lines of words.
const CELL = 'relative block aspect-[4/5] overflow-hidden rounded text-sm sm:aspect-square';

const WEEKDAYS = [0, 1, 2, 3, 4, 5, 6] as const;

/**
 * What a cell shows of its day is the first thing the day's block shows, the picture included, so a
 * cell asks for nothing the block has not already asked for.
 */
export function lead(t: Translate, day: DaySummary): { text: string; image: GridImage | null } | null {
    const [item] = day.items;

    if (item !== undefined) {
        return { text: item.kind === 'story' ? item.headline : said(t, item), image: item.image };
    }

    const [name] = [...day.newcomers, ...day.newGroups];

    return name === undefined ? null : { text: markedName(name.name, name.isAi ?? false, t), image: null };
}

/**
 * A day with an issue is a bordered link and a day without one is bare text
 * (docs/internals/home-issues.md, "The month page").
 */
export function MonthCalendar({ month, days }: { month: MonthRef; days: DaySummary[] }) {
    const date = useDateFormat();

    // By `date` alone: an issue stands on the day it is dated, whatever its window reaches back over.
    const issues = new Map(days.map((day) => [Number(day.date.slice(8, 10)), day]));

    return (
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
                            <td key={weekday} className="p-0 align-top">
                                {number !== null && <Day number={number} issue={issues.get(number)} />}
                            </td>
                        ))}
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

function Day({ number, issue }: { number: number; issue: DaySummary | undefined }) {
    const t = useT();
    const { civilDate } = useDateFormat();

    if (issue === undefined) {
        return <span className={cn(CELL, 'p-1 text-xs text-muted-foreground')}>{number}</span>;
    }

    const shown = lead(t, issue);
    const name = [daysCovered(t, civilDate, issue), shown?.text].filter(Boolean).join(', ');
    const frame = cn(CELL, 'border border-border text-foreground transition-colors');

    if (shown?.image) {
        return (
            <Link href={issue.href} aria-label={name} className={cn(frame, 'hover:opacity-90')}>
                <StoryPicture image={shown.image} shape="absolute inset-0 size-full" sizes="4rem" />
                <span aria-hidden className="absolute inset-x-0 top-0 block h-2/3 bg-linear-to-b from-scrim to-transparent" />
                <span className="absolute top-0 left-0 px-1 py-0.5 text-xs text-scrim-foreground">{number}</span>
            </Link>
        );
    }

    return (
        <Link href={issue.href} aria-label={name} className={cn(frame, 'p-1 hover:bg-selected/60')}>
            <span className="block text-xs leading-tight">{number}</span>
            {shown && (
                <span className="mt-0.5 line-clamp-2 text-3xs leading-tight break-all text-muted-foreground sm:line-clamp-3 sm:text-2xs">{shown.text}</span>
            )}
        </Link>
    );
}

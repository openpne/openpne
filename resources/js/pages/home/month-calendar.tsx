import { monthWeeks } from '@/lib/date';
import { morePhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { markedName } from '@/lib/identity-mark';
import { useDateFormat } from '@/lib/use-date-format';
import { cn } from '@/lib/utils';
import { dayAnchor, daysCovered, said } from './day-block';
import type { DaySummary, MonthRef } from './types';

type Translate = ReturnType<typeof useT>;

interface Chip {
    tone: 'story' | 'talk' | 'name';
    /** What the cell prints, cut where the cell ends. */
    text: string;
    /** What the link says of it, in full. */
    name: string;
}

const CHIPS = 3;

const WEEKDAYS = [0, 1, 2, 3, 4, 5, 6] as const;

const TONE: Record<Chip['tone'], string> = {
    story: 'bg-selected/15',
    talk: 'bg-secondary',
    name: 'text-muted-foreground',
};

const weekend = (weekday: number): string | undefined => (weekday === 0 ? 'text-sunday' : weekday === 6 ? 'text-saturday' : undefined);

/**
 * A day's items as its block lists them, then its names while there is room. A room is printed by
 * its group's name: a cell holds five characters or so, which is a name and not a sentence.
 */
export function chips(t: Translate, day: DaySummary): Chip[] {
    const items = day.items.map(
        (item): Chip =>
            item.kind === 'story'
                ? { tone: 'story', text: item.headline, name: item.headline }
                : { tone: 'talk', text: item.group.name, name: said(t, item) },
    );

    const names = [...day.newcomers, ...day.newGroups].map((named): Chip => {
        const name = markedName(named.name, named.isAi ?? false, t);

        return { tone: 'name', text: name, name };
    });

    return [...items, ...names].slice(0, CHIPS);
}

/**
 * A day with an issue leads to its block further down the page; what a cell prints is cut, never
 * wrapped, and the link says it in full (docs/internals/home-issues.md, "The month page").
 */
export function MonthCalendar({ month, days }: { month: MonthRef; days: DaySummary[] }) {
    const date = useDateFormat();

    // By `date` alone: an issue stands on the day it is dated, whatever its window reaches back over.
    const issues = new Map(days.map((day) => [Number(day.date.slice(8, 10)), day]));
    const today = date.siteDay(new Date().toISOString());
    const prefix = `${month.year}-${String(month.month).padStart(2, '0')}-`;

    return (
        <table className="w-full table-fixed border-collapse">
            <caption className="sr-only">{date.civilMonth(month.year, month.month)}</caption>
            <thead>
                <tr>
                    {WEEKDAYS.map((weekday) => (
                        <th
                            key={weekday}
                            scope="col"
                            abbr={date.weekday(weekday, 'long')}
                            className={cn('pb-1 text-center text-xs font-normal', weekend(weekday) ?? 'text-muted-foreground')}
                        >
                            {date.weekday(weekday)}
                        </th>
                    ))}
                </tr>
            </thead>
            <tbody>
                {monthWeeks(month.year, month.month).map((week) => (
                    <tr key={week.find((day) => day !== null)} className="border-t border-border">
                        {week.map((number, weekday) => (
                            // A week nothing happened in is a line of dates: the days are read below, not here.
                            <td
                                key={weekday}
                                className={cn('p-0 align-top', week.some((day) => day !== null && issues.has(day)) ? 'h-19 sm:h-24' : 'h-8')}
                            >
                                {number !== null && (
                                    <Day
                                        number={number}
                                        weekday={weekday}
                                        issue={issues.get(number)}
                                        today={today === `${prefix}${String(number).padStart(2, '0')}`}
                                    />
                                )}
                            </td>
                        ))}
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

/** The date has a line to itself, and the count of what is not printed shares it. */
function Top({ number, weekday, today, more }: { number: number; weekday: number; today: boolean; more: number }) {
    const t = useT();

    return (
        <span className="flex h-6 items-center justify-between px-1">
            <span className={cn('text-sm', today ? 'flex size-5 items-center justify-center rounded-full bg-foreground text-background' : weekend(weekday))}>
                {number}
            </span>
            {today && <span className="sr-only">{t('Today')}</span>}
            {more > 0 && <span className="text-2xs text-muted-foreground">+{more}</span>}
        </span>
    );
}

function Day({ number, weekday, issue, today }: { number: number; weekday: number; issue: DaySummary | undefined; today: boolean }) {
    const t = useT();
    const { civilDate } = useDateFormat();

    if (issue === undefined) {
        return (
            <span className="block">
                <Top number={number} weekday={weekday} today={today} more={0} />
            </span>
        );
    }

    const shown = chips(t, issue);
    const more = issue.more > 0 ? morePhrase(t, issue.more) : null;
    const name = [daysCovered(t, civilDate, issue), ...shown.map((chip) => chip.name), more].filter(Boolean).join(', ');

    return (
        <a href={`#${dayAnchor(issue.date)}`} aria-label={name} className="block h-full px-px pb-1 transition-colors hover:bg-muted/60">
            <Top number={number} weekday={weekday} today={today} more={issue.more} />
            {shown.map((chip, index) => (
                <span
                    key={index}
                    className={cn('mb-px block overflow-hidden rounded-sm px-0.5 text-2xs leading-4 whitespace-nowrap sm:px-1 sm:leading-5', TONE[chip.tone])}
                >
                    {chip.text}
                </span>
            ))}
        </a>
    );
}

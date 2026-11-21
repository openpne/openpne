import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronLeft, ChevronRight } from 'lucide-react';
import { useId, useState, type ReactNode } from 'react';
import { Heading } from '@/components/ui/heading';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';
import { useSiteDay } from '@/lib/use-site-day';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import { DayCalendar } from './day-calendar';
import { daysCovered } from './day-block';
import type { IssueRef } from './types';

function Phrase({ days }: { days: { from: string; to: string } | null }) {
    const t = useT();
    const { timezone } = usePage<PageProps>().props;
    const date = useDateFormat();
    // Read for the re-render alone: a page left open past midnight stops calling its day yesterday.
    useSiteDay(timezone);

    if (days === null) {
        return t('What happened');
    }

    if (days.from !== days.to) {
        return t('What happened from :from to :to', date.civilDaySpan(days.from, days.to));
    }

    // The calendar's yesterday, not the 06:00 day's: before 06:00 the latest issue is two calendar
    // days back, and calling it yesterday would be wrong by the clock on the wall.
    return (
        <time dateTime={days.from}>
            {date.civilDaysAgo(days.from) === 1
                ? t('What happened yesterday')
                : t('What happened on :date', { date: date.civilMonthDay(days.from, true) })}
        </time>
    );
}

/**
 * A side never breaks inside a word and the middle takes what width is left, so a long middle wraps
 * rather than pushing a side off the screen. Equal growth from a zero basis keeps it centred while
 * there is room.
 */
export function Band({ start, end, children }: { start: ReactNode; end: ReactNode; children: ReactNode }) {
    return (
        <div className="flex min-h-12 items-center gap-2 border-b border-border">
            <div className="flex flex-1 justify-start">{start}</div>
            <div className="min-w-0 text-balance text-center">{children}</div>
            <div className="flex flex-1 justify-end">{end}</div>
        </div>
    );
}

/** A way out of the band to one side, its words kept on one line. */
export function BandLink({ href, side, label, children }: { href: string; side: 'prev' | 'next'; label?: string; children: ReactNode }) {
    return (
        <Link
            href={href}
            aria-label={label}
            className={cn('flex min-h-11 items-center gap-0.5 whitespace-nowrap text-sm text-link hover:underline', side === 'prev' ? '-ml-1' : '-mr-1')}
        >
            {side === 'prev' && <ChevronLeft className="size-4" aria-hidden />}
            {children}
            {side === 'next' && <ChevronRight className="size-4" aria-hidden />}
        </Link>
    );
}

/** The days alone, as the month page names a day's block. */
function Dates({ days }: { days: { from: string; to: string } }) {
    const t = useT();
    const date = useDateFormat();
    const name = daysCovered(t, date, days);

    return days.from === days.to ? <time dateTime={days.from}>{name}</time> : name;
}

/** The month a past day's band opens. */
export interface BandCalendar {
    date: string;
    monthHref: string;
    days: IssueRef[];
}

interface Props {
    days: { from: string; to: string } | null;
    prev: IssueRef | null;
    next: IssueRef | null;
    /** A past day's: the band then names the days alone, as a rank under the section's h1, and opens their month. */
    calendar?: BandCalendar;
}

/** The page's heading in the band, the neighbouring days either side of it. */
export function Masthead({ days, prev, next, calendar }: Props) {
    const t = useT();
    const panel = useId();
    const [open, setOpen] = useState(false);

    const band = (
        <Band
            start={
                prev && (
                    <BandLink href={prev.href} side="prev" label={t('Earlier day')}>
                        {t('Show the earlier day')}
                    </BandLink>
                )
            }
            end={
                next ? (
                    <BandLink href={next.href} side="next" label={t('Later day')}>
                        {t('Show the later day')}
                    </BandLink>
                ) : (
                    // The hour is HomeIssueDay::HOUR's; the two change together.
                    <span className="text-right text-xs break-keep text-muted-foreground">{t('Next update at 06:00')}</span>
                )
            }
        >
            {calendar && days ? (
                <Heading as="h2" variant="minor">
                    <button
                        type="button"
                        onClick={() => setOpen((shown) => !shown)}
                        aria-expanded={open}
                        aria-controls={panel}
                        className="inline-flex min-h-11 items-center gap-1 rounded px-1 hover:bg-muted/40"
                    >
                        <Dates days={days} />
                        <span className="sr-only">{t('Calendar')}</span>
                        <ChevronDown className={cn('size-4 shrink-0 text-muted-foreground transition-transform', open && 'rotate-180')} aria-hidden />
                    </button>
                </Heading>
            ) : (
                // The h1 at a small rank on purpose (docs/internals/typography.md, "Heading roles").
                <Heading as="h1" variant="minor">
                    <Phrase days={days} />
                </Heading>
            )}
        </Band>
    );

    return (
        <>
            {band}
            {calendar && open && <DayCalendar id={panel} {...calendar} />}
        </>
    );
}

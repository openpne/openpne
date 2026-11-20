import { Link, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Heading } from '@/components/ui/heading';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';
import { useSiteDay } from '@/lib/use-site-day';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { IssueRef } from './types';

/** Decorative: the headings on either side of it say where one part of the page ends. */
export function DoubleRule({ heavyFirst }: { heavyFirst: boolean }) {
    return (
        <div aria-hidden className="flex flex-col gap-0.5">
            <div className={cn('bg-foreground', heavyFirst ? 'h-1' : 'h-px')} />
            <div className={cn('bg-foreground', heavyFirst ? 'h-px' : 'h-1')} />
        </div>
    );
}

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
 * The page's `h1` in a band between rules, the neighbouring days either side of it. The sides keep
 * their words on one line and the phrase takes what width is left, so a long one wraps rather than
 * pushing a step off the screen; equal growth from a zero basis keeps it centred while there is room.
 */
export function Masthead({ days, prev, next }: { days: { from: string; to: string } | null; prev: IssueRef | null; next: IssueRef | null }) {
    const t = useT();

    return (
        <div>
            <DoubleRule heavyFirst />
            <div className="flex min-h-12 items-center gap-2 border-b border-foreground">
                <div className="flex flex-1 justify-start">
                    {prev && (
                        <Link href={prev.href} className="-ml-1 flex min-h-11 items-center gap-0.5 whitespace-nowrap text-xs text-foreground hover:underline">
                            <ChevronLeft className="size-4" aria-hidden />
                            {t('Earlier day')}
                        </Link>
                    )}
                </div>
                <div className="min-w-0 text-balance text-center">
                    <Heading as="h1" variant="minor">
                        <Phrase days={days} />
                    </Heading>
                </div>
                <div className="flex flex-1 justify-end">
                    {next ? (
                        <Link href={next.href} className="-mr-1 flex min-h-11 items-center gap-0.5 whitespace-nowrap text-xs text-foreground hover:underline">
                            {t('Later day')}
                            <ChevronRight className="size-4" aria-hidden />
                        </Link>
                    ) : (
                        // The hour is HomeIssueDay::HOUR's; the two change together.
                        <span className="whitespace-nowrap text-2xs text-muted-foreground">{t('Next update at 06:00')}</span>
                    )}
                </div>
            </div>
        </div>
    );
}

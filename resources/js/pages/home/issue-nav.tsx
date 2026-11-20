import { Link, usePage } from '@inertiajs/react';
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';
import { useSiteDay } from '@/lib/use-site-day';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { IssueRef } from './types';

/** A neighbour at the foot of the page: what it is above, its name below. */
export function PagerStep({ href, caption, side, children }: { href: string; caption: string; side: 'prev' | 'next'; children: ReactNode }) {
    const Chevron = side === 'prev' ? ChevronLeft : ChevronRight;

    return (
        <Link href={href} className={cn('group flex min-h-14 items-center gap-1 text-foreground', side === 'next' && 'flex-row-reverse text-right')}>
            <Chevron className="size-4.5 shrink-0" aria-hidden />
            <span className="flex flex-col">
                <span className="text-2xs text-muted-foreground">{caption}</span>
                <span className="whitespace-nowrap text-sm group-hover:underline">{children}</span>
            </span>
        </Link>
    );
}

function Step({ issue, caption, side }: { issue: IssueRef; caption: string; side: 'prev' | 'next' }) {
    const { civilMonthDay } = useDateFormat();
    const { timezone } = usePage<PageProps>().props;
    // Read for the re-render alone: the date drops its year by the reader's year, which turns over at midnight.
    useSiteDay(timezone);

    return (
        <PagerStep href={issue.href} caption={caption} side={side}>
            <time dateTime={issue.date}>{civilMonthDay(issue.date, true)}</time>
        </PagerStep>
    );
}

/** Today has nothing after it, so its right third is empty rather than disabled. */
export function IssueNav({ prev, next, monthHref }: { prev: IssueRef | null; next: IssueRef | null; monthHref: string }) {
    const t = useT();

    return (
        <nav className="flex items-center gap-2" aria-label={t('Day navigation')}>
            <div className="flex flex-1 justify-start">{prev && <Step issue={prev} caption={t('Earlier day')} side="prev" />}</div>
            <Link
                href={monthHref}
                className="flex min-h-14 shrink-0 items-center gap-1 px-1.5 text-sm text-foreground underline underline-offset-4"
            >
                <CalendarDays className="size-4" aria-hidden />
                {t('View in calendar')}
            </Link>
            <div className="flex flex-1 justify-end">{next && <Step issue={next} caption={t('Later day')} side="next" />}</div>
        </nav>
    );
}

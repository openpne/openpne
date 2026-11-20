import { ChevronDown } from 'lucide-react';
import { useId, useState, type ReactNode } from 'react';
import { MonthGridBody } from '@/components/month-grid';
import { Heading } from '@/components/ui/heading';
import { Panel } from '@/components/ui/surface';
import { daysWorthPhrase, happeningDaysPhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { buildMonthRows } from '@/lib/month-grid';
import { useDateFormat } from '@/lib/use-date-format';
import { cn } from '@/lib/utils';
import { issueBucket, listedHref, type IssueMonth } from './issue-months';
import { Band, BandLink } from './masthead';
import { useMonthNames } from './month-nav';
import type { MonthRef } from './types';

interface Props {
    month: MonthRef;
    prev: MonthRef | null;
    next: MonthRef | null;
    months: IssueMonth[];
    /** The month's calendar, which the grid of months opens above. */
    children?: ReactNode;
}

/**
 * The month's name opens the grid of months over the calendar, which is not drawn while it is closed
 * (docs/internals/home-issues.md, "The month page").
 */
export function MonthBand({ month, prev, next, months, children }: Props) {
    const t = useT();
    const date = useDateFormat();
    const named = useMonthNames(month);
    const grid = useId();
    const [open, setOpen] = useState(false);

    const name = date.civilMonth(month.year, month.month);
    // With one month to its name a site has nowhere to jump to.
    const elsewhere = months.some((listed) => listed.year !== month.year || listed.month !== month.month);

    return (
        <>
            <Band
                start={
                    prev && (
                        <BandLink href={prev.href} side="prev" label={`${t('Earlier month')} ${named(prev)}`}>
                            {named(prev)}
                        </BandLink>
                    )
                }
                end={
                    next && (
                        <BandLink href={next.href} side="next" label={`${t('Later month')} ${named(next)}`}>
                            {named(next)}
                        </BandLink>
                    )
                }
            >
                <Heading as="h2" variant="minor">
                    {elsewhere ? (
                        <button
                            type="button"
                            onClick={() => setOpen((shown) => !shown)}
                            aria-expanded={open}
                            aria-controls={grid}
                            className="inline-flex min-h-11 items-center gap-1 rounded px-1 hover:bg-muted/40"
                        >
                            {name}
                            <span className="sr-only">{t('Jump to a month')}</span>
                            <ChevronDown className={cn('size-4 shrink-0 text-muted-foreground transition-transform', open && 'rotate-180')} aria-hidden />
                        </button>
                    ) : (
                        name
                    )}
                </Heading>
            </Band>

            <Panel>
                {open && (
                    <div id={grid} className="mb-3 border-b border-border pb-4">
                        <MonthGridBody
                            rows={buildMonthRows(months, date.currentYear(), listedHref(months), month)}
                            selected={month}
                            bucket={issueBucket}
                            countPhrase={(count) => happeningDaysPhrase(t, count)}
                            countText={(count) => daysWorthPhrase(t, count)}
                        />
                    </div>
                )}
                {children}
            </Panel>
        </>
    );
}

import { ChevronDown } from 'lucide-react';
import { useId, useState } from 'react';
import { MonthGridBody } from '@/components/month-grid';
import { Heading } from '@/components/ui/heading';
import { Panel } from '@/components/ui/surface';
import { happeningDaysPhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { buildMonthRows } from '@/lib/month-grid';
import { useDateFormat } from '@/lib/use-date-format';
import { cn } from '@/lib/utils';
import { issueBucket, listedHref, type IssueMonth } from './issue-months';
import { MonthCalendar } from './month-calendar';
import { MonthNav } from './month-nav';
import type { DaySummary, MonthRef } from './types';

interface Props {
    month: MonthRef;
    prev: MonthRef | null;
    next: MonthRef | null;
    days: DaySummary[];
    months: IssueMonth[];
}

/**
 * Every way to another day or month, in one place: the month's name opens the grid of months under
 * it, which is not drawn while it is closed (docs/internals/home-issues.md, "The month page").
 */
export function MonthPanel({ month, prev, next, days, months }: Props) {
    const t = useT();
    const date = useDateFormat();
    const grid = useId();
    const [open, setOpen] = useState(false);

    const name = date.civilMonth(month.year, month.month);
    // With one month to its name a site has nowhere to jump to.
    const elsewhere = months.some((listed) => listed.year !== month.year || listed.month !== month.month);

    return (
        <Panel>
            <MonthNav month={month} prev={prev} next={next}>
                <Heading as="h2" variant="group" className="shrink-0">
                    {elsewhere ? (
                        <button
                            type="button"
                            onClick={() => setOpen((shown) => !shown)}
                            aria-expanded={open}
                            aria-controls={grid}
                            className="flex min-h-11 items-center gap-1 rounded px-1 hover:bg-muted/40"
                        >
                            {name}
                            <span className="sr-only">{t('Jump to a month')}</span>
                            <ChevronDown className={cn('size-4 shrink-0 text-muted-foreground transition-transform', open && 'rotate-180')} aria-hidden />
                        </button>
                    ) : (
                        name
                    )}
                </Heading>
            </MonthNav>

            {open && (
                <div id={grid} className="mt-3 border-t border-border pt-4">
                    <MonthGridBody
                        rows={buildMonthRows(months, date.currentYear(), listedHref(months), month)}
                        selected={month}
                        bucket={issueBucket}
                        countPhrase={(count) => happeningDaysPhrase(t, count)}
                    />
                </div>
            )}

            {/* Out to the panel's edges: a column is as wide as the page allows, and words are cut by it. */}
            <div className="-mx-3 mt-3 sm:-mx-4">
                <MonthCalendar month={month} days={days} />
            </div>
        </Panel>
    );
}

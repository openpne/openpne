import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { headingVariants } from '@/components/ui/heading';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';
import { cn } from '@/lib/utils';
import { buildArchiveGrid, countBucket, selectedBeyondRecentYears, withKeyword, type MonthlyCount } from './archive-months';

interface Props {
    counts: MonthlyCount[];
    ownerId: number;
    selected: { year: number; month: number } | null;
    // Active archive keyword: threaded into month links and "show all" so filtering survives navigation.
    keyword?: string;
}

// Opacity ramp per heat bucket over the chosen-state token (bucket 0 = no fill).
const BUCKET_FILL = ['', 'bg-selected/10', 'bg-selected/20', 'bg-selected/30', 'bg-selected/45'] as const;

// Always-visible recent years; older years fold behind a disclosure.
const RECENT_YEARS = 2;

/**
 * Viewer-scoped year×month heat grid over a member's diary archive: each month cell links to that
 * month's archive (shaded by entry count), so the reader can scan when they wrote and jump to a
 * period. Hidden entirely when the member has no diaries.
 */
export function DiaryArchiveGrid({ counts, ownerId, selected, keyword }: Props) {
    const t = useT();
    const date = useDateFormat();
    const [showEarlier, setShowEarlier] = useState(false);

    const rows = buildArchiveGrid(counts, new Date().getFullYear(), ownerId, keyword, selected);
    if (rows.length === 0) {
        return null;
    }

    // A selection in an older year forces the fold open — collapsing would hide its own ring.
    const forceEarlier = selectedBeyondRecentYears(rows, selected, RECENT_YEARS);
    const expanded = showEarlier || forceEarlier;
    const hasEarlier = rows.length > RECENT_YEARS;
    const visibleRows = expanded ? rows : rows.slice(0, RECENT_YEARS);

    return (
        <Panel>
            <div className="space-y-4">
                {visibleRows.map((row) => (
                    <div key={row.year}>
                        <div className={cn(headingVariants({ variant: 'label' }), 'mb-1.5')}>{row.year}</div>
                        <div className="grid grid-cols-6 gap-1 sm:grid-cols-12">
                            {row.months.map((cell) => {
                                const isSelected = selected?.year === row.year && selected?.month === cell.month;
                                const label =
                                    cell.count > 0
                                        ? `${date.civilMonth(row.year, cell.month)}, ${t(':count entries', { count: cell.count })}`
                                        : date.civilMonth(row.year, cell.month);
                                const cellClass = cn(
                                    'flex min-h-11 flex-col items-center justify-center gap-0.5 rounded text-sm',
                                    cell.href ? BUCKET_FILL[countBucket(cell.count)] : 'text-muted-foreground',
                                    isSelected && 'ring-2 ring-selected',
                                );
                                const inner = (
                                    <>
                                        {/* A localized month name ("7月" / "Jul"), not a bare digit: the label is what
                                            makes the grid read as a calendar at a glance. */}
                                        <span className="text-xs">{date.civilMonthShort(cell.month)}</span>
                                        {/* The count line is always reserved (nbsp on empty months) so the month labels
                                            sit at the same height in every cell. Inherits the cell foreground: muted
                                            text fails contrast on the heavier fills. */}
                                        <span className="text-[0.625rem] leading-none">{cell.count > 0 ? cell.count : ' '}</span>
                                    </>
                                );
                                return cell.href ? (
                                    <Link
                                        key={cell.month}
                                        href={cell.href}
                                        aria-label={label}
                                        aria-current={isSelected ? 'true' : undefined}
                                        className={cn(cellClass, 'transition-colors hover:bg-selected/60')}
                                    >
                                        {inner}
                                    </Link>
                                ) : (
                                    <span key={cell.month} aria-label={label} aria-current={isSelected ? 'true' : undefined} className={cellClass}>
                                        {inner}
                                    </span>
                                );
                            })}
                        </div>
                    </div>
                ))}
            </div>

            {(hasEarlier || selected) && (
                <div className="mt-4 flex items-center gap-3 text-sm">
                    {hasEarlier && !forceEarlier && (
                        <button
                            type="button"
                            onClick={() => setShowEarlier((v) => !v)}
                            aria-expanded={expanded}
                            className="text-selected hover:underline"
                        >
                            {t('Show earlier years')}
                        </button>
                    )}
                    {selected && (
                        <Link href={withKeyword(`/diary/listMember/${ownerId}`, keyword)} className="ml-auto text-selected hover:underline">
                            {t('Show all %diary% entries')}
                        </Link>
                    )}
                </div>
            )}
        </Panel>
    );
}

import { Link } from '@inertiajs/react';
import { MonthGrid } from '@/components/month-grid';
import { entriesPhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';
import { buildArchiveGrid, countBucket, withKeyword, type MonthlyCount } from './archive-months';

interface Props {
    counts: MonthlyCount[];
    ownerId: number;
    selected: { year: number; month: number } | null;
    // Active archive keyword: threaded into month links and "show all" so filtering survives navigation.
    keyword?: string;
}

/** Hidden entirely when the member has no diaries. */
export function DiaryArchiveGrid({ counts, ownerId, selected, keyword }: Props) {
    const t = useT();
    const date = useDateFormat();

    return (
        <MonthGrid
            rows={buildArchiveGrid(counts, date.currentYear(), ownerId, keyword, selected)}
            selected={selected}
            bucket={countBucket}
            countPhrase={(count) => entriesPhrase(t, count)}
            footer={
                selected && (
                    <Link href={withKeyword(`/diary/listMember/${ownerId}`, keyword)} className="ml-auto text-selected hover:underline">
                        {t('Show all %diary% entries')}
                    </Link>
                )
            }
        />
    );
}

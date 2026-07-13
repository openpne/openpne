import { Head, Link, router, usePage } from '@inertiajs/react';
import { useConfirm } from '@/components/confirm-dialog';
import { Pagination } from '@/components/pagination';
import { dangerActionClass } from '@/components/ui/danger-link';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { DiaryArchiveGrid } from './archive-grid';
import type { MonthlyCount } from './archive-months';
import { DiaryRow } from './diary-row';
import type { DiaryAuthor, PaginatedDiaries } from './types';

interface ListProps extends PageProps {
    owner: DiaryAuthor;
    isOwner: boolean;
    diaries: PaginatedDiaries;
    monthlyCounts: MonthlyCount[];
    // The month the archive is narrowed to (highlighted in the grid), or null on the full archive.
    archive: { year: number; month: number } | null;
}

export default function DiaryList() {
    const t = useT();
    const confirm = useConfirm();
    const { owner, isOwner, diaries, monthlyCounts, archive } = usePage<ListProps>().props;
    // The tab title keeps the owner context; the heading (h1) is the generic section label from the
    // frame — for another member's archive the owner's name is already in the crumb above.
    const headTitle = isOwner ? t('%Diary%') : t(":name's %diary%", { name: owner.name });

    const deleteDiary = async (id: number, diaryTitle: string) => {
        if (await confirm({ title: t('Delete this %diary%?'), description: diaryTitle, confirmLabel: t('Delete'), danger: true })) {
            router.post(`/diary/delete/${id}`);
        }
    };

    const monthHeading = archive ? new Date(archive.year, archive.month - 1).toLocaleDateString(undefined, { year: 'numeric', month: 'long' }) : null;

    return (
        <>
            <Head title={headTitle} />

            <DiaryArchiveGrid counts={monthlyCounts} ownerId={owner.id} selected={archive} />

            {diaries.data.length === 0 ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('No %diary% entries to show.')}</p>
                </Panel>
            ) : (
                <>
                    <Panel
                        flush
                        title={monthHeading ?? undefined}
                        right={monthHeading ? <span className="text-xs font-normal text-muted-foreground">{t(':count entries', { count: diaries.meta.total })}</span> : undefined}
                    >
                        <List>
                            {diaries.data.map((entry) => (
                                <DiaryRow
                                    key={entry.id}
                                    diary={entry}
                                    rich
                                    actions={
                                        isOwner && (
                                            <>
                                                <Link href={`/diary/edit/${entry.id}`} className="text-muted-foreground hover:text-foreground">
                                                    {t('Edit')}
                                                </Link>
                                                <button type="button" onClick={() => deleteDiary(entry.id, entry.title)} className={dangerActionClass}>
                                                    {t('Delete')}
                                                </button>
                                            </>
                                        )
                                    }
                                />
                            ))}
                        </List>
                    </Panel>
                    <Pagination meta={diaries.meta} />
                </>
            )}
        </>
    );
}

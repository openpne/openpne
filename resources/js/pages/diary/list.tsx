import { Head, Link, usePage } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { PageHeading } from '@/components/page-heading';
import { Pagination } from '@/components/pagination';
import { ActionLink } from '@/components/ui/action-link';
import { DangerLink } from '@/components/ui/danger-link';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { DiaryRow } from './diary-row';
import type { DiaryAuthor, PaginatedDiaries } from './types';

interface ListProps extends PageProps {
    owner: DiaryAuthor;
    isOwner: boolean;
    diaries: PaginatedDiaries;
    period?: string; // calendar-archive label (e.g. "2026-03"), absent on the full archive
}

export default function DiaryList() {
    const t = useT();
    const { owner, isOwner, diaries, period } = usePage<ListProps>().props;
    const title = isOwner ? t('%Diary%') : t(":name's %diary%", { name: owner.name });

    return (
        <>
            <Head title={title} />
            <PageHeading
                title={
                    <>
                        {title}
                        {period && (
                            <span className="ml-2 text-base font-normal text-muted-foreground">{period}</span>
                        )}
                    </>
                }
                action={
                    isOwner && (
                        <ActionLink href="/m/diary/new">
                            <Pencil className="size-4" strokeWidth={2.25} aria-hidden />
                            {t('Write a %diary%')}
                        </ActionLink>
                    )
                }
            />

            {diaries.data.length === 0 ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('No %diary% entries to show.')}</p>
                </Panel>
            ) : (
                <>
                    <Panel flush>
                        <List>
                            {diaries.data.map((entry) => (
                                <DiaryRow
                                    key={entry.id}
                                    diary={entry}
                                    actions={
                                        isOwner && (
                                            <>
                                                <Link href={`/m/diary/edit/${entry.id}`} className="text-muted-foreground hover:text-foreground">
                                                    {t('Edit')}
                                                </Link>
                                                <DangerLink href={`/m/diary/deleteConfirm/${entry.id}`}>{t('Delete')}</DangerLink>
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

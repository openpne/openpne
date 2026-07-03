import { Head, Link, usePage } from '@inertiajs/react';
import { FlashMessage } from '@/components/flash-message';
import { Pagination } from '@/components/pagination';
import { DangerLink } from '@/components/ui/danger-link';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { DiaryCalendar } from './calendar';
import { DiaryRow } from './diary-row';
import type { DiaryAuthor, DiaryCalendarData, PaginatedDiaries } from './types';

interface ListProps extends PageProps {
    owner: DiaryAuthor;
    isOwner: boolean;
    diaries: PaginatedDiaries;
    calendar: DiaryCalendarData;
    period?: string; // calendar-archive label (e.g. "2026-03"), absent on the full archive
}

export default function DiaryList() {
    const t = useT();
    const { owner, isOwner, diaries, calendar, period, flash } = usePage<ListProps>().props;
    const title = isOwner ? t('%Diary%') : t(":name's %diary%", { name: owner.name });

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <div className="flex items-center justify-between gap-3">
                    <h1 className="text-xl font-semibold text-foreground">
                        {title}
                        {period && <span className="ml-2 text-base font-normal text-muted-foreground">{period}</span>}
                    </h1>
                    {isOwner && (
                        <Link href="/m/diary/new" className="shrink-0 text-sm text-link hover:underline">
                            {t('Write a %diary%')}
                        </Link>
                    )}
                </div>

                {flash.status && <FlashMessage>{flash.status}</FlashMessage>}
                {flash.error && <FlashMessage variant="error">{flash.error}</FlashMessage>}

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

                {/* Archive nav stays visible even on an empty month, so the reader can jump to a month that has entries. */}
                <DiaryCalendar calendar={calendar} ownerId={owner.id} />
            </main>
        </>
    );
}

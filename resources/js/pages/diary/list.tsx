import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { useConfirm } from '@/components/confirm-dialog';
import { Pagination } from '@/components/pagination';
import { SearchSubmitButton } from '@/components/search-submit-button';
import { dangerActionClass } from '@/components/ui/danger-link';
import { Input } from '@/components/ui/input';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { DiaryArchiveGrid } from './archive-grid';
import type { MonthlyCount } from './archive-months';
import { DiaryRow } from './diary-row';
import type { DiaryAvatarAuthor, PaginatedDiaries } from './types';

interface ListProps extends PageProps {
    owner: DiaryAvatarAuthor;
    isOwner: boolean;
    diaries: PaginatedDiaries;
    monthlyCounts: MonthlyCount[];
    // Active keyword filter (echoed from the server); empty when unfiltered.
    keyword: string;
    // The month the archive is narrowed to (highlighted in the grid), or null on the full archive.
    archive: { year: number; month: number } | null;
}

export default function DiaryList() {
    const t = useT();
    const confirm = useConfirm();
    const page = usePage<ListProps>();
    const { owner, isOwner, diaries, monthlyCounts, keyword, archive } = page.props;
    // The tab title keeps the owner context; the heading (h1) is the generic section label from the
    // frame — for another member's archive the owner's name is already in the crumb above.
    const headTitle = isOwner ? t('%Diary%') : t(":name's %diary%", { name: owner.name });

    const [keywordInput, setKeywordInput] = useState(keyword);
    const [searching, setSearching] = useState(false);

    const submitSearch = (e: FormEvent) => {
        e.preventDefault();
        // Keyword is orthogonal to the period: submit to the current path so a month archive stays
        // narrowed and gains the term filter. Empty keyword drops the param (clean, unfiltered URL).
        const path = page.url.split('?')[0] ?? page.url;
        router.get(path, keywordInput ? { keyword: keywordInput } : {}, {
            preserveState: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });
    };

    const deleteDiary = async (id: number, diaryTitle: string) => {
        if (await confirm({ title: t('Delete this %diary%?'), description: diaryTitle, confirmLabel: t('Delete'), danger: true })) {
            router.post(`/diary/delete/${id}`);
        }
    };

    const monthHeading = archive ? new Date(archive.year, archive.month - 1).toLocaleDateString(undefined, { year: 'numeric', month: 'long' }) : null;

    return (
        <>
            <Head title={headTitle} />

            <form onSubmit={submitSearch}>
                <div className="relative">
                    <label htmlFor="diary_archive_keyword" className="sr-only">
                        {t('Keyword')}
                    </label>
                    <Input
                        id="diary_archive_keyword"
                        type="search"
                        enterKeyHint="search"
                        placeholder={t('Search by keyword')}
                        value={keywordInput}
                        onChange={(e) => setKeywordInput(e.target.value)}
                        className="rounded-full pr-11 pl-5"
                    />
                    <SearchSubmitButton loading={searching} />
                </div>
            </form>

            <DiaryArchiveGrid counts={monthlyCounts} ownerId={owner.id} selected={archive} keyword={keyword} />

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

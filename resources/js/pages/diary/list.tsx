import { Head, Link, usePage } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { DiaryAuthor, PaginatedDiaries } from './types';

interface ListProps extends PageProps {
    owner: DiaryAuthor;
    isOwner: boolean;
    diaries: PaginatedDiaries;
}

export default function DiaryList() {
    const t = useT();
    const { owner, isOwner, diaries, flash } = usePage<ListProps>().props;
    const title = isOwner ? t('%Diary%') : t(":name's %diary%", { name: owner.name });

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">{title}</h1>

                {flash.status && <p role="status">{flash.status}</p>}
                {flash.error && <p role="alert">{flash.error}</p>}

                {diaries.data.length === 0 ? (
                    <p>{t('No %diary% entries to show.')}</p>
                ) : (
                    <>
                        <ul className="space-y-2">
                            {diaries.data.map((entry) => (
                                <li key={entry.id} className="flex items-center justify-between">
                                    <Link href={`/m/diary/${entry.id}`} className="hover:underline">
                                        {entry.title}
                                    </Link>
                                    <span className="text-sm text-muted-foreground">
                                        {new Date(entry.createdAt).toLocaleDateString()}
                                    </span>
                                    {isOwner && (
                                        <span className="space-x-2 text-sm">
                                            <Link href={`/m/diary/edit/${entry.id}`} className="hover:underline">
                                                {t('Edit')}
                                            </Link>
                                            <Link href={`/m/diary/deleteConfirm/${entry.id}`} className="hover:underline">
                                                {t('Delete')}
                                            </Link>
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                        <Pagination meta={diaries.meta} />
                    </>
                )}

                {isOwner && (
                    <Link href="/m/diary/new" className="hover:underline">
                        {t('Write a %diary%')}
                    </Link>
                )}
            </main>
        </>
    );
}

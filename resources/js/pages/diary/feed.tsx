import { Head, Link, usePage } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { PaginatedDiaries } from './types';

interface FeedProps extends PageProps {
    scope: 'all' | 'friends';
    diaries: PaginatedDiaries;
}

export default function DiaryFeed() {
    const t = useT();
    const { scope, diaries, flash } = usePage<FeedProps>().props;
    const title = scope === 'friends' ? t('%Diaries% of %My_friends%') : t('Recently Posted %Diaries%');

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">{title}</h1>

                {flash.status && <p role="status">{flash.status}</p>}

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
                                        {entry.author.name} &mdash; {new Date(entry.createdAt).toLocaleDateString()}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <Pagination meta={diaries.meta} />
                    </>
                )}
            </main>
        </>
    );
}

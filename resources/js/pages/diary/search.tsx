import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { PaginatedDiaries } from './types';

interface SearchProps extends PageProps {
    keyword: string;
    hasKeyword: boolean;
    diaries: PaginatedDiaries;
}

export default function DiarySearch() {
    const t = useT();
    const { keyword, hasKeyword, diaries } = usePage<SearchProps>().props;
    const form = useForm({ keyword });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.get('/m/diary/search', { preserveState: true });
    };

    return (
        <>
            <Head title={t('Search %diaries%')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">{t('Search %diaries%')}</h1>

                <form onSubmit={submit} className="flex gap-2">
                    <label htmlFor="diary_search_keyword" className="sr-only">
                        {t('Keyword')}
                    </label>
                    <input
                        id="diary_search_keyword"
                        type="text"
                        value={form.data.keyword}
                        onChange={(e) => form.setData('keyword', e.target.value)}
                        className="flex-1 rounded border px-2 py-1"
                    />
                    <button type="submit" disabled={form.processing}>
                        {t('Search')}
                    </button>
                </form>

                <section className="space-y-2">
                    <h2 className="text-lg font-semibold">{hasKeyword ? t('Search Results') : t('Recently Posted %Diaries%')}</h2>
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
                </section>
            </main>
        </>
    );
}

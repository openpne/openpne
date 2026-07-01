import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { CommunityImage } from '@/components/community-image';
import { Pagination } from '@/components/pagination';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunityCategory, PaginatedCommunities } from './types';

interface SearchProps extends PageProps {
    communities: PaginatedCommunities;
    keyword: string;
    categoryId: number | null;
    categories: CommunityCategory[];
}

export default function CommunitySearch() {
    const t = useT();
    const { communities, keyword, categoryId, categories } = usePage<SearchProps>().props;
    const [form, setForm] = useState({ keyword, categoryId: categoryId ?? 0 });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        // 0 / empty means "no filter" — drop them so the URL stays clean and the pager query matches.
        router.get(
            '/m/community/search',
            { keyword: form.keyword || undefined, category_id: form.categoryId || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title={t('%Communities%')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-2xl font-semibold">{t('%Communities%')}</h1>

                <form onSubmit={submit} className="flex flex-wrap gap-2">
                    <label htmlFor="community_keyword" className="sr-only">
                        {t('Keyword')}
                    </label>
                    <input
                        id="community_keyword"
                        type="text"
                        value={form.keyword}
                        onChange={(e) => setForm((f) => ({ ...f, keyword: e.target.value }))}
                        className="min-w-0 flex-1 rounded border px-2 py-1"
                    />
                    <label htmlFor="community_category" className="sr-only">
                        {t('Category')}
                    </label>
                    <select
                        id="community_category"
                        value={form.categoryId}
                        onChange={(e) => setForm((f) => ({ ...f, categoryId: Number(e.target.value) }))}
                        className="rounded border px-2 py-1"
                    >
                        <option value={0}>{t('All categories')}</option>
                        {categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name}
                            </option>
                        ))}
                    </select>
                    <button type="submit">{t('Search')}</button>
                </form>

                {communities.data.length === 0 ? (
                    <p>{t('No %communities% found.')}</p>
                ) : (
                    <>
                        <ul className="space-y-3">
                            {communities.data.map((community) => (
                                <li key={community.id}>
                                    <Link href={`/m/community/${community.id}`} className="flex gap-3">
                                        <CommunityImage id={community.id} name={community.name} src={community.imageUrl} className="size-14" />
                                        <div className="min-w-0 flex-1">
                                            <p className="font-medium">{community.name}</p>
                                            {community.category && <p className="text-xs text-muted-foreground">{community.category.name}</p>}
                                            {community.description && (
                                                <p className="line-clamp-2 text-sm text-muted-foreground">{community.description}</p>
                                            )}
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        <Pagination meta={communities.meta} />
                    </>
                )}
            </main>
        </>
    );
}

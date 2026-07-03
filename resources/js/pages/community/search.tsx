import { Head, Link, router, usePage } from '@inertiajs/react';
import { Search, Users } from 'lucide-react';
import { useState } from 'react';
import { CommunityImage } from '@/components/community-image';
import { CountBadge } from '@/components/entry-row';
import { Pagination } from '@/components/pagination';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { List, ListRow, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { CommunityTabs } from './community-tabs';
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
                <div className="flex items-center justify-between gap-3">
                    <h1 className="text-2xl font-semibold">{t('%Communities%')}</h1>
                    <Link href="/m/community/edit" className="shrink-0 text-sm text-link hover:underline">
                        {t('Create a %community%')}
                    </Link>
                </div>

                <CommunityTabs active="browse" />

                <form onSubmit={submit} className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-[12rem] flex-1">
                        <label htmlFor="community_keyword" className="sr-only">
                            {t('Keyword')}
                        </label>
                        <Input
                            id="community_keyword"
                            type="search"
                            enterKeyHint="search"
                            placeholder={t('Search by %community% name')}
                            value={form.keyword}
                            onChange={(e) => setForm((f) => ({ ...f, keyword: e.target.value }))}
                            className="rounded-full pr-11 pl-5"
                        />
                        <button
                            type="submit"
                            aria-label={t('Search')}
                            className="absolute top-1/2 right-1.5 flex size-9 -translate-y-1/2 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <Search className="size-4" aria-hidden />
                        </button>
                    </div>
                    <label htmlFor="community_category" className="sr-only">
                        {t('Category')}
                    </label>
                    <Select
                        id="community_category"
                        value={form.categoryId}
                        onChange={(e) => setForm((f) => ({ ...f, categoryId: Number(e.target.value) }))}
                        className="w-auto rounded-full pl-5"
                    >
                        <option value={0}>{t('All categories')}</option>
                        {categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name}
                            </option>
                        ))}
                    </Select>
                </form>

                {communities.data.length === 0 ? (
                    <Panel>
                        <p className="text-sm text-muted-foreground">{t('No %communities% found.')}</p>
                    </Panel>
                ) : (
                    <>
                        <Panel flush>
                            <List>
                                {communities.data.map((community) => (
                                    <ListRow key={community.id} href={`/m/community/${community.id}`} chevron>
                                        <CommunityImage id={community.id} name={community.name} src={community.imageUrl} className="size-12" decorative />
                                        <div className="min-w-0 flex-1">
                                            <p className="font-medium text-foreground">{community.name}</p>
                                            <p className="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground">
                                                {community.category && (
                                                    <>
                                                        <span className="truncate">{community.category.name}</span>
                                                        <span aria-hidden>·</span>
                                                    </>
                                                )}
                                                <CountBadge
                                                    icon={Users}
                                                    count={community.memberCount}
                                                    srLabel={t(':count members', { count: community.memberCount })}
                                                />
                                            </p>
                                            {community.description && (
                                                <p className="line-clamp-2 text-sm text-muted-foreground">{community.description}</p>
                                            )}
                                        </div>
                                    </ListRow>
                                ))}
                            </List>
                        </Panel>
                        <Pagination meta={communities.meta} />
                    </>
                )}
            </main>
        </>
    );
}

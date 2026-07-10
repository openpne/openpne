import { Head, router, usePage } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { useState } from 'react';
import { CommunityImage } from '@/components/community-image';
import { CountBadge } from '@/components/entry-row';
import { Pagination } from '@/components/pagination';
import { SearchSubmitButton } from '@/components/search-submit-button';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { List, ListRow, Panel } from '@/components/ui/surface';
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
    const [searching, setSearching] = useState(false);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        // 0 / empty means "no filter" — drop them so the URL stays clean and the pager query matches.
        router.get(
            '/community/search',
            { keyword: form.keyword || undefined, category_id: form.categoryId || undefined },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setSearching(true),
                onFinish: () => setSearching(false),
            },
        );
    };

    return (
        <>
            <Head title={t('%Communities%')} />
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
                    <SearchSubmitButton loading={searching} />
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
                                <ListRow key={community.id} href={`/community/${community.id}`} chevron>
                                    <CommunityImage name={community.name} src={community.imageUrl} className="size-12" decorative />
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
        </>
    );
}

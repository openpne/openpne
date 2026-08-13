import { Head, Link, router, usePage } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { useState } from 'react';
import { CommunityImage } from '@/components/community-image';
import { CountBadge } from '@/components/entry-row';
import { Pagination } from '@/components/pagination';
import { SearchSubmitButton } from '@/components/search-submit-button';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { List, ListRow, Panel, stretchedLink } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { CommunityCategory, PaginatedCommunities } from './types';

interface SearchProps extends PageProps {
    groups: PaginatedCommunities;
    keyword: string;
    categoryId: number | null;
    categories: CommunityCategory[];
}

export default function CommunitySearch() {
    const t = useT();
    const { groups, keyword, categoryId, categories } = usePage<SearchProps>().props;
    const [form, setForm] = useState({ keyword, categoryId: categoryId ?? 0 });
    const [searching, setSearching] = useState(false);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        // 0 / empty means "no filter" — drop them so the URL stays clean and the pager query matches.
        router.get(
            '/groups',
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

            {groups.data.length === 0 ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('No %communities% found.')}</p>
                </Panel>
            ) : (
                <>
                    <Panel flush>
                        <List>
                            {groups.data.map((group) => (
                                <ListRow key={group.id} rowLink chevron>
                                    <CommunityImage name={group.name} src={group.imageUrl} className="size-12" decorative />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-foreground">
                                            <Link href={`/groups/${group.id}`} className={stretchedLink}>
                                                {group.name}
                                            </Link>
                                        </p>
                                        <p className="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground">
                                            {group.category && (
                                                <>
                                                    <span className="truncate">{group.category.name}</span>
                                                    <span aria-hidden>·</span>
                                                </>
                                            )}
                                            <CountBadge
                                                icon={Users}
                                                count={group.memberCount}
                                                srLabel={t(':count members', { count: group.memberCount })}
                                            />
                                        </p>
                                        {group.description && (
                                            <p className="line-clamp-2 text-sm text-muted-foreground">{group.description}</p>
                                        )}
                                    </div>
                                </ListRow>
                            ))}
                        </List>
                    </Panel>
                    <Pagination meta={groups.meta} />
                </>
            )}
        </>
    );
}

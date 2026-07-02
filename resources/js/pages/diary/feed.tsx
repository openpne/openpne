import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { type FormEvent } from 'react';
import { FlashMessage } from '@/components/flash-message';
import { Pagination } from '@/components/pagination';
import { Input } from '@/components/ui/input';
import { List, ListRow, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { PaginatedDiaries } from './types';

interface FeedProps extends PageProps {
    variant: 'recent' | 'friends' | 'search';
    keyword: string;
    hasKeyword: boolean;
    diaries: PaginatedDiaries;
}

export default function DiaryFeed() {
    const t = useT();
    const { variant, keyword, hasKeyword, diaries, flash } = usePage<FeedProps>().props;
    const searchable = variant !== 'friends';
    const title =
        variant === 'friends'
            ? t('%Diaries% of %My_friends%')
            : variant === 'search' && hasKeyword
              ? t('Search Results')
              : t('Recently Posted %Diaries%');
    const form = useForm({ keyword });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.get('/m/diary/search', { preserveState: true });
    };

    return (
        <>
            <Head title={title} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <h1 className="text-xl font-semibold text-foreground">{title}</h1>

                {searchable && (
                    <form onSubmit={submit}>
                        <div className="relative">
                            <label htmlFor="diary_search_keyword" className="sr-only">
                                {t('Keyword')}
                            </label>
                            <Input
                                id="diary_search_keyword"
                                type="search"
                                enterKeyHint="search"
                                placeholder={t('Search by keyword')}
                                value={form.data.keyword}
                                onChange={(e) => form.setData('keyword', e.target.value)}
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
                    </form>
                )}

                {flash.status && <FlashMessage>{flash.status}</FlashMessage>}

                {diaries.data.length === 0 ? (
                    <Panel>
                        <p className="text-sm text-muted-foreground">{t('No %diary% entries to show.')}</p>
                    </Panel>
                ) : (
                    <>
                        <Panel flush>
                            <List>
                                {diaries.data.map((entry) => (
                                    <ListRow key={entry.id} href={`/m/diary/${entry.id}`} chevron>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-medium text-foreground">
                                                {entry.title}
                                                {entry.hasImages && (
                                                    <span title={t('This entry has photos')} aria-label={t('This entry has photos')}>
                                                        {' '}
                                                        📷
                                                    </span>
                                                )}
                                            </p>
                                            <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                                {entry.author.name} &mdash; {new Date(entry.createdAt).toLocaleDateString()}
                                            </p>
                                        </div>
                                    </ListRow>
                                ))}
                            </List>
                        </Panel>
                        <Pagination meta={diaries.meta} />
                    </>
                )}
            </main>
        </>
    );
}

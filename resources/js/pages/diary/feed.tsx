import { Head, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { Pagination } from '@/components/pagination';
import { SearchSubmitButton } from '@/components/search-submit-button';
import { Input } from '@/components/ui/input';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { DiaryRow } from './diary-row';
import type { PaginatedDiaries } from './types';

interface FeedProps extends PageProps {
    variant: 'recent' | 'friends' | 'search';
    keyword: string;
    hasKeyword: boolean;
    diaries: PaginatedDiaries;
}

export default function DiaryFeed() {
    const t = useT();
    const { variant, keyword, hasKeyword, diaries } = usePage<FeedProps>().props;
    const searchable = variant !== 'friends';
    // The hub header (h1 = nav label, tabs, write action) comes from the frame; the browser Head
    // title keeps the fuller per-view description.
    const headTitle =
        variant === 'friends'
            ? t('%Diaries% of %My_friends%')
            : variant === 'search' && hasKeyword
              ? t('Search Results')
              : t('Recently Posted %Diaries%');
    const form = useForm({ keyword });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.get('/diary/search', { preserveState: true });
    };

    return (
        <>
            <Head title={headTitle} />
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
                        <SearchSubmitButton loading={form.processing} />
                    </div>
                </form>
            )}

            {variant === 'search' && hasKeyword && (
                <p className="text-sm text-muted-foreground">{t('Search results for :keyword', { keyword })}</p>
            )}

            {diaries.data.length === 0 ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('No %diary% entries to show.')}</p>
                </Panel>
            ) : (
                <>
                    <Panel flush>
                        <List>
                            {diaries.data.map((entry) => (
                                <DiaryRow key={entry.id} diary={entry} rich />
                            ))}
                        </List>
                    </Panel>
                    <Pagination meta={diaries.meta} />
                </>
            )}
        </>
    );
}

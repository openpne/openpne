import { Head, useForm, usePage } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { type FormEvent } from 'react';
import { FlashMessage } from '@/components/flash-message';
import { PageHeading } from '@/components/page-heading';
import { PageTabs } from '@/components/page-tabs';
import { Pagination } from '@/components/pagination';
import { SearchSubmitButton } from '@/components/search-submit-button';
import { ActionLink } from '@/components/ui/action-link';
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
    const { variant, keyword, hasKeyword, diaries, flash, auth } = usePage<FeedProps>().props;
    const searchable = variant !== 'friends';
    // Hub h1 matches the nav label; the specific view (friends / search) lives in the tabs + the
    // in-page search context, and the browser Head title keeps the fuller description.
    const hubTitle = t('%Diaries%');
    const headTitle =
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
            <Head title={headTitle} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                <PageHeading
                    title={hubTitle}
                    action={
                        auth.user && (
                            <ActionLink href="/m/diary/new">
                                <Pencil className="size-4" strokeWidth={2.25} aria-hidden />
                                {t('Write a %diary%')}
                            </ActionLink>
                        )
                    }
                />

                <PageTabs
                    ariaLabel={hubTitle}
                    items={[
                        { href: '/m/diary/list', label: t('All'), active: variant !== 'friends' },
                        { href: '/m/diary/listFriend', label: t('%Friends%'), active: variant === 'friends' },
                    ]}
                />

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
                                    <DiaryRow key={entry.id} diary={entry} showAuthor />
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

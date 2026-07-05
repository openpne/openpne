import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { type FormEvent } from 'react';
import { FlashMessage } from '@/components/flash-message';
import { PageHeading } from '@/components/page-heading';
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

/** All / Friends switch between the two diary feeds (OpenPNE 3 list vs listFriend). */
function FeedTab({ href, label, active }: { href: string; label: string; active: boolean }) {
    return (
        <Link
            href={href}
            aria-current={active ? 'page' : undefined}
            className={
                'min-h-11 border-b-2 px-4 py-2 text-sm font-medium transition-colors ' +
                (active
                    ? 'border-foreground text-foreground'
                    : 'border-transparent text-muted-foreground hover:text-foreground')
            }
        >
            {label}
        </Link>
    );
}

export default function DiaryFeed() {
    const t = useT();
    const { variant, keyword, hasKeyword, diaries, flash, auth } = usePage<FeedProps>().props;
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
                <PageHeading
                    title={title}
                    action={
                        auth.user && (
                            <ActionLink href="/m/diary/new">
                                <Pencil className="size-4" strokeWidth={2.25} aria-hidden />
                                {t('Write a %diary%')}
                            </ActionLink>
                        )
                    }
                />

                <nav className="flex gap-1 border-b border-border" aria-label={title}>
                    <FeedTab href="/m/diary/list" label={t('All')} active={variant !== 'friends'} />
                    <FeedTab href="/m/diary/listFriend" label={t('%Friends%')} active={variant === 'friends'} />
                </nav>

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

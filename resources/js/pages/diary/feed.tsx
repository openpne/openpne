import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { type FormEvent } from 'react';
import { FlashMessage } from '@/components/flash-message';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
                    <form onSubmit={submit} className="flex gap-2">
                        <label htmlFor="diary_search_keyword" className="sr-only">
                            {t('Keyword')}
                        </label>
                        <div className="flex-1">
                            <Input
                                id="diary_search_keyword"
                                type="text"
                                value={form.data.keyword}
                                onChange={(e) => form.setData('keyword', e.target.value)}
                            />
                        </div>
                        <Button type="submit" loading={form.processing}>
                            {t('Search')}
                        </Button>
                    </form>
                )}

                {flash.status && <FlashMessage>{flash.status}</FlashMessage>}

                {diaries.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">{t('No %diary% entries to show.')}</p>
                ) : (
                    <>
                        <ul className="space-y-2">
                            {diaries.data.map((entry) => (
                                <li key={entry.id} className="flex items-center justify-between gap-3">
                                    <Link href={`/m/diary/${entry.id}`} className="truncate text-foreground hover:underline">
                                        {entry.title}
                                        {entry.hasImages && (
                                            <span className="imageIcon" title={t('This entry has photos')} aria-label={t('This entry has photos')}>
                                                {' '}📷
                                            </span>
                                        )}
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

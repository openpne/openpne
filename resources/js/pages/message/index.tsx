import { Head, Link, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { Pagination } from '@/components/pagination';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { MessageBoxSlug, PaginatedMessages } from './types';

interface IndexProps extends PageProps {
    box: MessageBoxSlug;
    messages: PaginatedMessages;
}

const BOX: Record<MessageBoxSlug, { label: string; path: string }> = {
    receive: { label: 'Inbox', path: '/m/message/receiveList' },
    sent: { label: 'Sent Message', path: '/m/message/sendList' },
    draft: { label: 'Drafts', path: '/m/message/draftList' },
    trash: { label: 'Trash', path: '/m/message/dustList' },
};

const ORDER: MessageBoxSlug[] = ['receive', 'sent', 'draft', 'trash'];

// The per-box row destination (OpenPNE 3 paths): the show page for a sent/received/trashed message,
// the edit form for a draft.
const SHOW_PATH: Record<MessageBoxSlug, (id: number) => string> = {
    receive: (id) => `/m/message/read/${id}`,
    sent: (id) => `/m/message/check/${id}`,
    trash: (id) => `/m/message/checkDelete/${id}`,
    draft: (id) => `/m/message/edit/${id}`,
};

export default function MessageIndex() {
    const t = useT();
    const { box, messages, flash } = usePage<IndexProps>().props;
    const current = BOX[box];
    const showPath = SHOW_PATH[box];

    return (
        <>
            <Head title={t(current.label)} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                {flash.status && <p role="status">{flash.status}</p>}

                <h1 className="text-2xl font-semibold">{t(current.label)}</h1>

                <nav className="flex flex-wrap gap-4 border-b text-sm" aria-label={t('Message boxes')}>
                    {ORDER.map((slug) => (
                        <Link
                            key={slug}
                            href={BOX[slug].path}
                            aria-current={slug === box ? 'page' : undefined}
                            className={
                                slug === box
                                    ? 'border-b-2 border-blue-600 pb-2 font-medium'
                                    : 'pb-2 text-muted-foreground hover:underline'
                            }
                        >
                            {t(BOX[slug].label)}
                        </Link>
                    ))}
                </nav>

                {messages.data.length === 0 ? (
                    <p>{t('There are no messages')}</p>
                ) : (
                    <>
                        <ul className="divide-y">
                            {messages.data.map((m) => (
                                <li key={m.id} className="flex items-start gap-3 py-3">
                                    <Avatar
                                        id={m.counterparty?.id ?? 0}
                                        name={m.counterparty?.name ?? ''}
                                        src={m.counterparty?.imageUrl ?? null}
                                        size="sm"
                                    />
                                    <div className="min-w-0 flex-1">
                                        <p className={m.unread ? 'truncate font-semibold' : 'truncate'}>
                                            <Link href={showPath(m.id)} className="hover:underline">
                                                {m.subject || t('(No subject)')}
                                            </Link>
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {m.counterparty?.name ?? t('Withdrawn member')} &mdash;{' '}
                                            {new Date(m.date).toLocaleString()}
                                        </p>
                                    </div>
                                    {m.unread && (
                                        <span className="mt-1 size-2 shrink-0 rounded-full bg-blue-600" aria-label={t('Unread')} />
                                    )}
                                </li>
                            ))}
                        </ul>
                        <Pagination meta={messages.meta} />
                    </>
                )}
            </main>
        </>
    );
}

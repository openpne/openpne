import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Avatar } from '@/components/avatar';
import { useConfirm } from '@/components/confirm-dialog';
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

// The bulk actions a box offers (OpenPNE 3 MessageDeleteForm): trash from the active boxes; restore
// or purge from the trash. `confirm` marks the destructive purge, which asks first.
interface BulkAction {
    label: string;
    action: 'delete' | 'restore' | 'purge';
    danger?: boolean;
    confirm?: boolean;
}

const BULK: Record<MessageBoxSlug, BulkAction[]> = {
    receive: [{ label: 'Delete', action: 'delete', danger: true }],
    sent: [{ label: 'Delete', action: 'delete', danger: true }],
    draft: [{ label: 'Delete', action: 'delete', danger: true }],
    trash: [
        { label: 'Restore', action: 'restore' },
        { label: 'Delete permanently', action: 'purge', danger: true, confirm: true },
    ],
};

export default function MessageIndex() {
    const t = useT();
    const confirm = useConfirm();
    const { box, messages, flash } = usePage<IndexProps>().props;
    const current = BOX[box];
    const showPath = SHOW_PATH[box];

    const [selected, setSelected] = useState<number[]>([]);
    const ids = messages.data.map((m) => m.id);
    const allSelected = ids.length > 0 && selected.length === ids.length;

    const toggle = (id: number) =>
        setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));
    const toggleAll = () => setSelected(allSelected ? [] : ids);

    const runBulk = async ({ action, confirm: needsConfirm }: BulkAction) => {
        if (selected.length === 0) return;
        if (needsConfirm && !(await confirm({ title: t('Delete the selected messages permanently?'), confirmLabel: t('Delete'), danger: true }))) {
            return;
        }
        router.post(
            '/m/message/bulk',
            { box, action, ids: selected, ...(needsConfirm ? { confirm: true } : {}) },
            { preserveScroll: true, onSuccess: () => setSelected([]) },
        );
    };

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
                        <div className="flex flex-wrap items-center gap-4 text-sm">
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={allSelected} onChange={toggleAll} />
                                {t('Select All')}
                            </label>
                            {selected.length > 0 && <span className="text-muted-foreground">{t(':count selected', { count: selected.length })}</span>}
                            <div className="ml-auto flex gap-3">
                                {BULK[box].map((a) => (
                                    <button
                                        key={a.action}
                                        type="button"
                                        onClick={() => runBulk(a)}
                                        disabled={selected.length === 0}
                                        className={`hover:underline disabled:opacity-40 ${a.danger ? 'text-red-600' : ''}`}
                                    >
                                        {t(a.label)}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <ul className="divide-y">
                            {messages.data.map((m) => (
                                <li key={m.id} className="flex items-start gap-3 py-3">
                                    <input
                                        type="checkbox"
                                        checked={selected.includes(m.id)}
                                        onChange={() => toggle(m.id)}
                                        aria-label={m.subject || t('(No subject)')}
                                        className="mt-1"
                                    />
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

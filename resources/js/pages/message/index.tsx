import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Avatar } from '@/components/avatar';
import { useConfirm } from '@/components/confirm-dialog';
import { Pagination } from '@/components/pagination';
import { Timestamp } from '@/components/timestamp';
import { UnreadDot, UnreadLabel, unreadTextClass } from '@/components/unread';
import { Checkbox } from '@/components/ui/checkbox';
import { List, ListRow, Panel, stretchedLink } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { MessageBoxSlug, PaginatedMessages } from './types';

interface IndexProps extends PageProps {
    box: MessageBoxSlug;
    messages: PaginatedMessages;
}

// Box labels for the browser Head title; the hub tabs live in the chrome registry.
const BOX_LABEL: Record<MessageBoxSlug, string> = {
    receive: 'Inbox',
    sent: 'Sent Message',
    draft: 'Drafts',
    trash: 'Trash',
};

// The per-box row destination (OpenPNE 3 paths): the show page for a sent/received/trashed message,
// the edit form for a draft.
const SHOW_PATH: Record<MessageBoxSlug, (id: number) => string> = {
    receive: (id) => `/message/read/${id}`,
    sent: (id) => `/message/check/${id}`,
    trash: (id) => `/message/checkDelete/${id}`,
    draft: (id) => `/message/edit/${id}`,
};

// The bulk actions a box offers: trash from the active boxes; restore
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
    const { box, messages } = usePage<IndexProps>().props;
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
            '/message/bulk',
            { box, action, ids: selected, ...(needsConfirm ? { confirm: true } : {}) },
            { preserveScroll: true, onSuccess: () => setSelected([]) },
        );
    };

    return (
        <>
            <Head title={t(BOX_LABEL[box])} />

            {messages.data.length === 0 ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('There are no messages')}</p>
                </Panel>
            ) : (
                <>
                    <Panel flush>
                        <div className="flex flex-wrap items-center gap-4 border-b border-border px-4 py-3 text-sm sm:px-5">
                            <label className="flex items-center gap-2 text-foreground">
                                <Checkbox checked={allSelected} onChange={toggleAll} />
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
                                        className={`rounded-md outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-40 ${a.danger ? 'text-destructive' : 'text-link'}`}
                                    >
                                        {t(a.label)}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <List>
                            {messages.data.map((m) => (
                                <ListRow key={m.id} rowLink chevron className="items-start">
                                    {/* Raised above the row link, which covers everything else. */}
                                    <Checkbox
                                        checked={selected.includes(m.id)}
                                        onChange={() => toggle(m.id)}
                                        aria-label={m.subject || t('(No subject)')}
                                        className="relative z-10 mt-1"
                                    />
                                    <Avatar
                                        id={m.counterparty?.id ?? 0}
                                        name={m.counterparty?.name ?? ''}
                                        src={m.counterparty?.imageUrl ?? null}
                                        color={m.counterparty?.avatarColor ?? null}
                                        size="md"
                                        decorative
                                    />
                                    <div className="min-w-0 flex-1">
                                        <p className={cn('truncate text-foreground', unreadTextClass(m.unread))}>
                                            <Link href={showPath(m.id)} className={stretchedLink}>
                                                {m.unread && <UnreadLabel />}
                                                {m.subject || t('(No subject)')}
                                            </Link>
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {m.counterparty?.name ?? t('Withdrawn member')} &mdash;{' '}
                                            <Timestamp at={m.date} preset="listStamp" />
                                        </p>
                                    </div>
                                    {m.unread && <UnreadDot className="mt-1" />}
                                </ListRow>
                            ))}
                        </List>
                    </Panel>
                    <Pagination meta={messages.meta} />
                </>
            )}
        </>
    );
}

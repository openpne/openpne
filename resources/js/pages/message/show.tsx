import { Head, Link, router, usePage } from '@inertiajs/react';
import { ImageGrid } from '@/components/image-grid';
import { Avatar } from '@/components/avatar';
import { useConfirm } from '@/components/confirm-dialog';
import { Timestamp } from '@/components/timestamp';
import { Heading } from '@/components/ui/heading';
import { UserText } from '@/components/user-text';
import { ActionLink } from '@/components/ui/action-link';
import { Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { MessageBoxSlug, MessageDetail } from './types';

interface ShowProps extends PageProps {
    message: MessageDetail;
}

// The per-box show route (OpenPNE 3 paths), for the prev/next pager.
const SHOW_PATH: Record<MessageBoxSlug, (id: number) => string> = {
    receive: (id) => `/message/read/${id}`,
    sent: (id) => `/message/check/${id}`,
    trash: (id) => `/message/checkDelete/${id}`,
    draft: (id) => `/message/read/${id}`, // unreachable: a draft has no show page
};

export default function MessageShow() {
    const t = useT();
    const confirm = useConfirm();
    const { message } = usePage<ShowProps>().props;
    const showPath = SHOW_PATH[message.box];
    const counterpartyHeading = message.viewerIsSender ? t('Recipient') : t('Sender');
    const onlyCounterparty = message.counterparties.length === 1;
    // Reply is offered on a received message whose sender still exists (the inbox counterparty).
    const canReply = message.box === 'receive' && message.counterparties.length > 0;

    const trash = (path: string) => router.post(path);
    const purge = async () => {
        if (await confirm({ title: t('Delete this message permanently?'), confirmLabel: t('Delete'), danger: true })) {
            router.post(`/message/deleteComplete/${message.id}`);
        }
    };

    return (
        <>
            <Head title={message.subject || t('(No subject)')} />

            {(message.previousId !== null || message.nextId !== null) && (
                <nav className="flex justify-between text-sm" aria-label={t('Message navigation')}>
                    {message.previousId !== null ? (
                        <Link href={showPath(message.previousId)} className="text-link hover:underline">
                            {t('Previous')}
                        </Link>
                    ) : (
                        <span />
                    )}
                    {message.nextId !== null ? (
                        <Link href={showPath(message.nextId)} className="text-link hover:underline">
                            {t('Next')}
                        </Link>
                    ) : (
                        <span />
                    )}
                </nav>
            )}

            <Heading variant="page">{message.subject}</Heading>

            <Panel bodyClassName="space-y-3 text-foreground">
                <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm">
                    <dt className="text-muted-foreground">{counterpartyHeading}</dt>
                    <dd>
                        {message.counterparties.length === 0 ? (
                            <span>{t('Withdrawn member')}</span>
                        ) : (
                            <ul className="flex flex-wrap gap-x-4 gap-y-1">
                                {message.counterparties.map((m) => (
                                    <li key={m.id} className="flex items-center gap-1">
                                        {/* The same exactly-one rule the top bar applies to this set: one counterparty is
                                            the person the message is with, so it gets the content size; several are an
                                            audience roster (only reachable from upgraded OpenPNE 3 sends) and stay dense. */}
                                        <Avatar id={m.id} name={m.name} src={m.imageUrl} color={m.avatarColor} size={onlyCounterparty ? 'md' : 'sm'} decorative />
                                        <Link href={`/member/${m.id}`} className="text-link hover:underline">
                                            {m.name}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </dd>
                    <dt className="text-muted-foreground">{t('Created At')}</dt>
                    <dd><Timestamp at={message.createdAt} preset="absolute" /></dd>
                </dl>

                <ImageGrid images={message.images} size="size-24" />

                <div className="whitespace-pre-wrap break-words">
                    <UserText text={message.body} />
                </div>

                <div className="flex flex-wrap items-center gap-4 pt-2">
                    {canReply && <ActionLink href={`/message/reply/${message.id}`}>{t('Reply')}</ActionLink>}
                    {message.box === 'receive' && (
                        <button type="button" onClick={() => trash(`/message/deleteReceiveMessage/${message.id}`)} className="rounded-md text-sm text-destructive outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring">
                            {t('Delete')}
                        </button>
                    )}
                    {message.box === 'sent' && (
                        <button type="button" onClick={() => trash(`/message/deleteSendMessage/${message.id}`)} className="rounded-md text-sm text-destructive outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring">
                            {t('Delete')}
                        </button>
                    )}
                    {message.box === 'trash' && (
                        <>
                            <button type="button" onClick={() => trash(`/message/restore/${message.id}`)} className="rounded-md text-sm text-link outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring">
                                {t('Restore')}
                            </button>
                            <button type="button" onClick={purge} className="rounded-md text-sm text-destructive outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring">
                                {t('Delete permanently')}
                            </button>
                        </>
                    )}
                </div>
            </Panel>
        </>
    );
}

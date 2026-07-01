import { Head, Link, router, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { useConfirm } from '@/components/confirm-dialog';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { MessageBoxSlug, MessageDetail, MessageImage } from './types';

interface ShowProps extends PageProps {
    message: MessageDetail;
}

// The per-box show route (OpenPNE 3 paths), for the prev/next pager.
const SHOW_PATH: Record<MessageBoxSlug, (id: number) => string> = {
    receive: (id) => `/m/message/read/${id}`,
    sent: (id) => `/m/message/check/${id}`,
    trash: (id) => `/m/message/checkDelete/${id}`,
    draft: (id) => `/m/message/read/${id}`, // unreachable: a draft has no show page
};

const BOX: Record<MessageBoxSlug, { label: string; path: string }> = {
    receive: { label: 'Inbox', path: '/m/message/receiveList' },
    sent: { label: 'Sent Message', path: '/m/message/sendList' },
    draft: { label: 'Drafts', path: '/m/message/draftList' },
    trash: { label: 'Trash', path: '/m/message/dustList' },
};

function ImageGrid({ images }: { images: MessageImage[] }) {
    if (images.length === 0) {
        return null;
    }

    return (
        <ul className="flex flex-wrap gap-2">
            {images.map((image) => (
                <li key={image.id}>
                    <a href={image.url} target="_blank" rel="noopener noreferrer">
                        <img src={image.thumbnailUrl} alt="" className="size-24 rounded object-cover" />
                    </a>
                </li>
            ))}
        </ul>
    );
}

export default function MessageShow() {
    const t = useT();
    const confirm = useConfirm();
    const { message, flash } = usePage<ShowProps>().props;
    const showPath = SHOW_PATH[message.box];
    const box = BOX[message.box];
    const counterpartyHeading = message.viewerIsSender ? t('Recipient') : t('Sender');
    // OpenPNE 3 offers Reply on a received message whose sender still exists (the inbox counterparty).
    const canReply = message.box === 'receive' && message.counterparties.length > 0;

    const trash = (path: string) => router.post(path);
    const purge = async () => {
        if (await confirm({ title: t('Delete this message permanently?'), confirmLabel: t('Delete'), danger: true })) {
            router.post(`/m/message/deleteComplete/${message.id}`);
        }
    };

    return (
        <>
            <Head title={message.subject || t('(No subject)')} />
            <main className="mx-auto max-w-2xl space-y-4 px-4 py-8">
                {flash.status && <p role="status">{flash.status}</p>}
                {flash.error && <p role="alert">{flash.error}</p>}

                <p className="text-sm">
                    <Link href={box.path} className="text-muted-foreground hover:underline">
                        &larr; {t(box.label)}
                    </Link>
                </p>

                {(message.previousId !== null || message.nextId !== null) && (
                    <nav className="flex justify-between text-sm" aria-label={t('Message navigation')}>
                        {message.previousId !== null ? (
                            <Link href={showPath(message.previousId)} className="hover:underline">
                                {t('Previous')}
                            </Link>
                        ) : (
                            <span />
                        )}
                        {message.nextId !== null ? (
                            <Link href={showPath(message.nextId)} className="hover:underline">
                                {t('Next')}
                            </Link>
                        ) : (
                            <span />
                        )}
                    </nav>
                )}

                <article className="space-y-3">
                    <h1 className="text-2xl font-semibold">{message.subject}</h1>

                    <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm">
                        <dt className="font-medium text-muted-foreground">{counterpartyHeading}</dt>
                        <dd>
                            {message.counterparties.length === 0 ? (
                                <span>{t('Withdrawn member')}</span>
                            ) : (
                                <ul className="flex flex-wrap gap-x-4 gap-y-1">
                                    {message.counterparties.map((m) => (
                                        <li key={m.id} className="flex items-center gap-1">
                                            <Avatar id={m.id} name={m.name} src={m.imageUrl} size="sm" />
                                            <Link href={`/m/member/${m.id}`} className="hover:underline">
                                                {m.name}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </dd>
                        <dt className="font-medium text-muted-foreground">{t('Created At')}</dt>
                        <dd>{new Date(message.createdAt).toLocaleString()}</dd>
                    </dl>

                    <ImageGrid images={message.images} />

                    <div className="whitespace-pre-wrap break-words">{message.body}</div>

                    <div className="flex flex-wrap items-center gap-3 pt-2">
                        {canReply && (
                            <Link
                                href={`/m/message/reply/${message.id}`}
                                className="inline-block min-h-11 rounded-full bg-blue-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-blue-700"
                            >
                                {t('Reply')}
                            </Link>
                        )}
                        {message.box === 'receive' && (
                            <button type="button" onClick={() => trash(`/m/message/deleteReceiveMessage/${message.id}`)} className="text-sm text-red-600 hover:underline">
                                {t('Delete')}
                            </button>
                        )}
                        {message.box === 'sent' && (
                            <button type="button" onClick={() => trash(`/m/message/deleteSendMessage/${message.id}`)} className="text-sm text-red-600 hover:underline">
                                {t('Delete')}
                            </button>
                        )}
                        {message.box === 'trash' && (
                            <>
                                <button type="button" onClick={() => trash(`/m/message/restore/${message.id}`)} className="text-sm hover:underline">
                                    {t('Restore')}
                                </button>
                                <button type="button" onClick={purge} className="text-sm text-red-600 hover:underline">
                                    {t('Delete permanently')}
                                </button>
                            </>
                        )}
                    </div>
                </article>
            </main>
        </>
    );
}

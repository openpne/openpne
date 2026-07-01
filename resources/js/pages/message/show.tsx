import { Head, Link, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
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
    const { message, flash } = usePage<ShowProps>().props;
    const showPath = SHOW_PATH[message.box];
    const box = BOX[message.box];
    const counterpartyHeading = message.viewerIsSender ? t('Recipient') : t('Sender');

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
                </article>
            </main>
        </>
    );
}

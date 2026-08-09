import { Head, Link, router, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { Pagination, type PaginationMeta } from '@/components/pagination';
import { PushPrompt } from '@/components/push-prompt';
import { Button } from '@/components/ui/button';
import { List, Panel } from '@/components/ui/surface';
import { formatDateTime } from '@/lib/date';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface FeedActor {
    id: number;
    name: string;
    imageUrl: string | null;
    avatarColor: string | null;
}

interface FeedItem {
    id: string;
    kind: string;
    reason: string | null;
    /** Already translated: the sentence is resolved server-side so both surfaces share one source. */
    label: string;
    createdAt: string;
    read: boolean;
    actor: FeedActor | null;
}

interface FeedProps extends PageProps {
    feed: { data: FeedItem[]; meta: PaginationMeta };
}

/** The per-event notification feed. Opening a row (a POST, so nothing prefetches it into "read")
 *  marks it read and lands on what it is about; the page itself never marks anything. */
export default function NotificationsIndex() {
    const t = useT();
    const { feed, unread } = usePage<FeedProps>().props;
    const title = t('Notifications');

    return (
        <>
            <Head title={title} />
            <PushPrompt />
            {(unread?.notifications ?? 0) > 0 && (
                <div className="flex justify-end">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => router.post('/notifications/read-all', {}, { preserveScroll: true })}
                    >
                        {t('Mark all as read')}
                    </Button>
                </div>
            )}
            {feed.data.length === 0 ? (
                <Panel>
                    <p className="text-sm text-muted-foreground">{t('No notifications yet.')}</p>
                </Panel>
            ) : (
                <Panel flush>
                    <List>
                        {feed.data.map((item) => (
                            <li key={item.id}>
                                <Link
                                    method="post"
                                    as="button"
                                    href={`/notifications/${item.id}/open`}
                                    className="flex min-h-11 w-full items-center gap-3 px-4 py-3 text-left text-foreground sm:px-5 transition-colors hover:bg-muted/40 active:bg-muted/60"
                                >
                                    <Avatar
                                        id={item.actor?.id ?? 0}
                                        name={item.actor?.name ?? t('Withdrawn member')}
                                        src={item.actor?.imageUrl ?? null}
                                        color={item.actor?.avatarColor ?? null}
                                        size="sm"
                                        decorative
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className={'block text-sm ' + (item.read ? 'text-muted-foreground' : 'font-medium')}>
                                            {item.label}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">{formatDateTime(item.createdAt)}</span>
                                    </span>
                                    {!item.read && (
                                        <span className="size-2 shrink-0 rounded-full bg-selected">
                                            <span className="sr-only">{t('Unread')}</span>
                                        </span>
                                    )}
                                </Link>
                            </li>
                        ))}
                    </List>
                </Panel>
            )}
            {feed.data.length > 0 && <Pagination meta={feed.meta} />}
        </>
    );
}

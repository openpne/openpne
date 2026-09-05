import { Head, Link, router, usePage } from '@inertiajs/react';
import { Settings } from 'lucide-react';
import { Avatar } from '@/components/avatar';
import { Pagination, type PaginationMeta } from '@/components/pagination';
import { PushPrompt } from '@/components/push-prompt';
import { Timestamp } from '@/components/timestamp';
import { UnreadDot, UnreadLabel, unreadTextClass } from '@/components/unread';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface FeedActor {
    id: number;
    name: string;
    imageUrl: string | null;
    avatarColor: string | null;
    isAi: boolean;
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

/** Opening a row marks it read, and so does reaching that target any other way
 *  (docs/internals/notifications.md, "The three layers"); the feed itself never marks anything. */
export default function NotificationsIndex() {
    const t = useT();
    const { feed, unread } = usePage<FeedProps>().props;
    const title = t('Notifications');

    // Nothing re-reads the feed here: the app-wide revalidation on a restore does it
    // (lib/revalidate-on-restore.ts).

    return (
        <>
            <Head title={title} />
            <PushPrompt />
            {/* Both controls cluster right and take the same weight: one framed and one bare would
                read as an inconsistency rather than as a hierarchy. */}
            <div className="flex items-center justify-end gap-2">
                {(unread?.notifications ?? 0) > 0 && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => router.post('/notifications/read-all', {}, { preserveScroll: true })}
                    >
                        {t('Mark all as read')}
                    </Button>
                )}
                {/* The accessible name stays the full one at every width, and the short label is
                    contained in it (label-in-name). */}
                <ActionLink
                    href="/member/config/notifications"
                    variant="outline"
                    size="sm"
                    aria-label={t('Notification settings')}
                >
                    <Settings className="size-4" aria-hidden />
                    <span className="sm:hidden">{t('Settings')}</span>
                    <span className="hidden sm:inline">{t('Notification settings')}</span>
                </ActionLink>
            </div>
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
                                        isAi={item.actor?.isAi ?? false}
                                        size="md"
                                        decorative
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className={cn('block text-sm', unreadTextClass(!item.read))}>
                                            {!item.read && <UnreadLabel />}
                                            {item.label}
                                        </span>
                                        <Timestamp at={item.createdAt} preset="relative" className="block text-xs text-muted-foreground" />
                                    </span>
                                    {!item.read && <UnreadDot />}
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

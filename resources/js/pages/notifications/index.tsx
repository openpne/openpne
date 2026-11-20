import { Head, router, usePage } from '@inertiajs/react';
import { Settings } from 'lucide-react';
import { LoadOlder } from '@/components/load-older';
import { PushPrompt } from '@/components/push-prompt';
import { StreamEmpty, StreamHead } from '@/components/stream-empty';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { List, Panel } from '@/components/ui/surface';
import { useT } from '@/lib/i18n';
import type { PageProps } from '@/types';
import { type FeedItem, NotificationRow } from './notification-row';

interface FeedProps extends PageProps {
    feed: { data: FeedItem[] };
    streamGeneration: string;
    headUrl: string | null;
}

/** Opening a row marks it read, and so does reaching that target any other way
 *  (docs/internals/notifications.md, "The three layers"); the feed itself never marks anything. */
export default function NotificationsIndex() {
    const t = useT();
    const { feed, unread, streamGeneration, headUrl } = usePage<FeedProps>().props;
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
                <StreamEmpty headUrl={headUrl} empty={t('No notifications yet.')} older={t('No older notifications.')} />
            ) : (
                <>
                    <StreamHead headUrl={headUrl} />
                    <LoadOlder data="feed" generation={streamGeneration} end={t('No older notifications.')}>
                        <Panel flush>
                            <List>
                                {feed.data.map((item) => (
                                    <li key={item.id}>
                                        <NotificationRow item={item} />
                                    </li>
                                ))}
                            </List>
                        </Panel>
                    </LoadOlder>
                </>
            )}
        </>
    );
}

import { Head, Link, router, usePage } from '@inertiajs/react';
import { Settings } from 'lucide-react';
import { useEffect } from 'react';
import { Avatar } from '@/components/avatar';
import { Pagination, type PaginationMeta } from '@/components/pagination';
import { PushPrompt } from '@/components/push-prompt';
import { Timestamp } from '@/components/timestamp';
import { UnreadDot, UnreadLabel, unreadTextClass } from '@/components/unread';
import { ActionLink } from '@/components/ui/action-link';
import { Button } from '@/components/ui/button';
import { List, Panel } from '@/components/ui/surface';
import { consumeHistoryRestore } from '@/lib/history-restore';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
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

    // Coming back here does not re-read it — Inertia rebuilds a popstate target from its own history
    // state, and the browser restores a bfcache entry whole — so the row the member opened one
    // navigation ago would still say unread. Read state is the server's `read_at` and nothing else,
    // so arriving from either kind of restore asks for it again rather than patching it locally.
    useEffect(() => {
        const refresh = () => router.reload({ only: ['feed', 'unread'] });

        if (consumeHistoryRestore()) {
            refresh();
        }

        const onPageshow = (event: PageTransitionEvent) => {
            if (event.persisted) {
                refresh();
            }
        };
        window.addEventListener('pageshow', onPageshow);

        return () => window.removeEventListener('pageshow', onPageshow);
    }, []);

    return (
        <>
            <Head title={title} />
            <PushPrompt />
            {/* What arrives here is decided elsewhere, so the feed carries the way to that page. Both
                controls cluster right: below lg the h1 folds into the bar, and a lone noun phrase at
                the left edge lands where the heading would have been and reads as one. They take the
                same weight — one framed and one bare read as an inconsistency rather than as a
                hierarchy — and settings sits outermost, the corner it is looked for in. */}
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
                {/* The full name wherever it fits, the bare word where it does not: beside the
                    read-all button, English needs a ~360px viewport for "Notification settings"
                    alone. Cutting over at sm rather than at that measured edge leaves room for a
                    longer word in another locale. The two reasons to shorten and to be precise sit
                    on opposite sides of a breakpoint — the left nav's own "Settings", which this
                    word would otherwise double, appears only from lg. The accessible name stays the
                    full one at every width, and the short label is contained in it (label-in-name). */}
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
                                        size="sm"
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

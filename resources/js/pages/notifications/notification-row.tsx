import { Link } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { KindMark } from '@/components/kind-mark';
import { Timestamp } from '@/components/timestamp';
import { UnreadDot, UnreadLabel, unreadTextClass } from '@/components/unread';
import type { ContentKind } from '@/lib/content-kinds';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface FeedActor {
    id: number;
    name: string;
    imageUrl: string | null;
    avatarColor: string | null;
    isAi: boolean;
}

export interface FeedItem {
    id: string;
    kind: string;
    /** Null on an unknown kind or a row missing its id; a target removed since keeps its mark. */
    contentKind: ContentKind | null;
    reason: string | null;
    /** Already translated: the sentence is resolved server-side so both surfaces share one source. */
    label: string;
    createdAt: string;
    read: boolean;
    actor: FeedActor | null;
}

export function NotificationRow({ item }: { item: FeedItem }) {
    const t = useT();

    return (
        <Link
            method="post"
            as="button"
            href={`/notifications/${item.id}/open`}
            className="flex min-h-11 w-full items-center gap-3 px-4 py-3 text-left text-foreground sm:px-5 transition-colors hover:bg-muted/40 active:bg-muted/60"
        >
            <Avatar
                id={item.actor?.id ?? 0}
                name={item.actor?.name ?? t('%Withdrawn_member%')}
                src={item.actor?.imageUrl ?? null}
                color={item.actor?.avatarColor ?? null}
                isAi={item.actor?.isAi ?? false}
                size="md"
                decorative
            />
            <span className="min-w-0 flex-1">
                {item.contentKind && <KindMark kind={item.contentKind} className="mb-0.5" />}
                <span className={cn('block text-sm', unreadTextClass(!item.read))}>
                    {!item.read && <UnreadLabel />}
                    {item.label}
                </span>
                <Timestamp at={item.createdAt} preset="relative" className="block text-xs text-muted-foreground" />
            </span>
            {!item.read && <UnreadDot />}
        </Link>
    );
}

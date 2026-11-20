import { Link } from '@inertiajs/react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { Card } from '@/components/card';
import { CommunityImage } from '@/components/community-image';
import { KindMark } from '@/components/kind-mark';
import { Timestamp } from '@/components/timestamp';
import { Heading } from '@/components/ui/heading';
import { stretchedLink } from '@/components/ui/surface';
import { messagesPhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { PictureStrip } from './picture-strip';
import type { TalkBurst, TalkExcerptMessage } from './types';

/**
 * `listStamp` and not the room's clock time: an issue is read on days after its own, where a bare
 * clock time names no hour in particular.
 */
function ExcerptMessage({ message }: { message: TalkExcerptMessage }) {
    const t = useT();
    const author = message.author;
    const name = author?.name ?? t('%Withdrawn_member%');

    return (
        <li className="flex min-w-0 gap-2">
            <Avatar
                id={author?.id ?? 0}
                name={name}
                src={author?.imageUrl ?? null}
                color={author?.avatarColor ?? null}
                isAi={author?.isAi ?? false}
                size="sm"
                decorative
            />
            <div className="min-w-0 flex-1">
                <div className="flex min-w-0 flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                    <span className="truncate text-foreground">{name}</span>
                    <AiChip isAi={author?.isAi ?? false} />
                    <Timestamp at={message.createdAt} preset="listStamp" />
                </div>
                {message.body.trim() !== '' && <p className="line-clamp-3 whitespace-pre-wrap break-words text-sm">{message.body}</p>}
                <PictureStrip images={message.images} className="mt-1" />
            </div>
        </li>
    );
}

/**
 * Not the catch-up card it reads like: that one requires the two actions that spend a backlog, and
 * this has none to spend. The card is one link (docs/internals/home-issues.md, "Rendering"), so the
 * mentions in its messages are text.
 */
export function TalkBurstCard({ burst }: { burst: TalkBurst }) {
    const t = useT();

    return (
        <Card className="relative px-4 py-4 sm:px-5">
            <KindMark kind="talk" className="mb-2" />
            <div className="flex min-w-0 items-center gap-2">
                <CommunityImage name={burst.group.name} src={burst.group.imageUrl} className="size-10" textClassName="text-sm" decorative />
                <Heading as="h2" variant="section" className="min-w-0 flex-1 truncate">
                    <Link href={burst.href} className={stretchedLink}>
                        {burst.group.name}
                    </Link>
                </Heading>
                <span className="shrink-0 text-xs text-muted-foreground">{messagesPhrase(t, burst.count)}</span>
            </div>

            {burst.messages.length > 0 && (
                <ul className="mt-4 space-y-3">
                    {burst.messages.map((message) => (
                        <ExcerptMessage key={message.id} message={message} />
                    ))}
                </ul>
            )}
        </Card>
    );
}

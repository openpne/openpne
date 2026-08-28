import { Link } from '@inertiajs/react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { Card } from '@/components/card';
import { CommunityImage } from '@/components/community-image';
import { EntityText } from '@/components/entity-text';
import { Timestamp } from '@/components/timestamp';
import { Heading } from '@/components/ui/heading';
import { useT } from '@/lib/i18n';
import { PictureStrip } from './picture-strip';
import type { TalkBurst, TalkExcerptMessage } from './types';

/**
 * One line of the conversation, drawn the way the room draws it: a face, a name, the time it was
 * said at, the words, and whatever was posted with them. A withdrawn author keeps their place with
 * the established label — the message stays, the person is gone.
 *
 * Clipped at three lines. A burst is several messages on a page of several bursts, and one long turn
 * taking the height of a story would make the excerpt an article of its own.
 *
 * `listStamp` and not the room's clock time: an issue is read on its own day and on days after it,
 * and a bare `09:41` under a week-old dateline names no hour in particular.
 */
function ExcerptMessage({ message }: { message: TalkExcerptMessage }) {
    const t = useT();
    const author = message.author;
    const name = author?.name ?? t('Withdrawn member');

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
                {message.body.trim() !== '' && (
                    <p className="line-clamp-3 whitespace-pre-wrap break-words text-sm">
                        <EntityText text={message.body} mentions={message.mentions} />
                    </p>
                )}
                <PictureStrip images={message.images} className="mt-1" />
            </div>
        </li>
    );
}

/**
 * A run of talk in one group, as the front page reports it: whose room it is, how much was said, and
 * the end of it to read.
 *
 * The excerpt is the card. A count says a number happened; the last few turns say what the room is
 * like, which is the thing a reader can judge from outside it — and it carries the faces and the
 * pictures along the way, so neither needs a row of its own.
 *
 * Not the catch-up card (group/talk/unread-digest.tsx), though it reads like one: that card requires
 * the two actions that spend a backlog, and this has no backlog to spend. Its one way out is the
 * room itself.
 */
export function TalkBurstCard({ burst }: { burst: TalkBurst }) {
    const t = useT();

    return (
        <Card className="space-y-3 px-4 py-3 sm:px-5">
            <div className="flex min-w-0 items-center gap-2">
                <CommunityImage name={burst.group.name} src={burst.group.imageUrl} className="size-10" textClassName="text-sm" decorative />
                <Link href={burst.href} className="min-w-0 truncate text-sm text-link hover:underline">
                    {burst.group.name}
                </Link>
            </div>

            <Heading as="h2" variant="group">
                {t(':count messages', { count: burst.count })}
            </Heading>

            {burst.messages.length > 0 && (
                <ul className="space-y-3">
                    {burst.messages.map((message) => (
                        <ExcerptMessage key={message.id} message={message} />
                    ))}
                </ul>
            )}

            <div className="text-sm">
                <Link href={burst.href} className="text-link hover:underline">
                    {t('Open talk')}
                </Link>
            </div>
        </Card>
    );
}

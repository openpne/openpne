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
 * `listStamp` and not the room's clock time: an issue is read on days after its own, where a bare
 * clock time names no hour in particular.
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
 * Not the catch-up card it reads like: that one requires the two actions that spend a backlog, and
 * this has none to spend.
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

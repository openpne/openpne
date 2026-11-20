import { Link } from '@inertiajs/react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { CommunityImage } from '@/components/community-image';
import { EntityText } from '@/components/entity-text';
import { Timestamp } from '@/components/timestamp';
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
 * The room's name heads it but is not a link, so the one way in is the line at its foot, which names
 * what is left unread when the excerpt is not all of it.
 */
export function TalkBurstRoom({ burst }: { burst: TalkBurst }) {
    const t = useT();
    const rest = burst.count - burst.messages.length;

    return (
        <li className="space-y-3 px-4 pt-4 pb-1.5 sm:px-5">
            <div className="flex min-w-0 items-center gap-2">
                <CommunityImage name={burst.group.name} src={burst.group.imageUrl} className="size-10" textClassName="text-sm" decorative />
                {/* A heading styled as the row's content line (docs/internals/typography.md, "Heading roles"). */}
                <h3 className="min-w-0 flex-1 truncate text-base text-foreground">{burst.group.name}</h3>
                <span className="shrink-0 text-xs text-muted-foreground">{messagesPhrase(t, burst.count)}</span>
            </div>

            {burst.messages.length > 0 && (
                <ul className="space-y-3">
                    {burst.messages.map((message) => (
                        <ExcerptMessage key={message.id} message={message} />
                    ))}
                </ul>
            )}

            <Link href={burst.href} className="inline-flex min-h-11 items-center text-sm text-link hover:underline">
                {rest === 1 ? t('1 more · Open talk') : rest > 1 ? t(':count more · Open talk', { count: rest }) : t('Open talk')}
            </Link>
        </li>
    );
}

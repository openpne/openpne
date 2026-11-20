import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { CommunityImage } from '@/components/community-image';
import { EntityText } from '@/components/entity-text';
import { Timestamp } from '@/components/timestamp';
import { Heading } from '@/components/ui/heading';
import { messagesPhrase } from '@/lib/count-phrase';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
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
        <li className="flex min-w-0 gap-2.5">
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
                    <p className="line-clamp-3 whitespace-pre-wrap break-words text-sm leading-relaxed">
                        <EntityText text={message.body} mentions={message.mentions} />
                    </p>
                )}
                <PictureStrip images={message.images} className="mt-1.5" />
            </div>
        </li>
    );
}

/**
 * The room's name heads it but is not a link, so the one way in is the line at its foot, which names
 * what is left unread when the excerpt is not all of it.
 */
export function TalkBurstSection({ burst, first }: { burst: TalkBurst; first: boolean }) {
    const t = useT();
    const rest = burst.count - burst.messages.length;

    return (
        <section className={cn('flex flex-col gap-2.5', !first && 'border-t border-border pt-5')}>
            <div className="flex min-w-0 items-center gap-2.5">
                <CommunityImage name={burst.group.name} src={burst.group.imageUrl} className="size-7" textClassName="text-xs" decorative />
                <Heading as="h3" variant="group" face="serif" className="min-w-0 flex-1 truncate">
                    {burst.group.name}
                </Heading>
                <span className="shrink-0 text-xs text-muted-foreground">{messagesPhrase(t, burst.count)}</span>
            </div>

            {burst.messages.length > 0 && (
                <ul className="space-y-3">
                    {burst.messages.map((message) => (
                        <ExcerptMessage key={message.id} message={message} />
                    ))}
                </ul>
            )}

            <Link href={burst.href} className="flex min-h-11 items-center gap-0.5 self-start text-sm text-foreground hover:underline">
                {rest === 1 ? t('1 more · Open talk') : rest > 1 ? t(':count more · Open talk', { count: rest }) : t('Open talk')}
                <ChevronRight className="size-4" aria-hidden />
            </Link>
        </section>
    );
}

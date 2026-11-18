import { Link } from '@inertiajs/react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { Timestamp } from '@/components/timestamp';
import { FileChips } from '@/components/file-chip';
import { ImageGrid } from '@/components/image-grid';
import { Heading } from '@/components/ui/heading';
import { UserText } from '@/components/user-text';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ConversationMessage } from './types';

export function ConversationMessageRow({
    message,
    highlighted = false,
    grouped = false,
    separatorAbove = false,
}: {
    message: ConversationMessage;
    highlighted?: boolean;
    grouped?: boolean;
    /** Whether a heading or the unread line stands above this row, and so already holds the space. */
    separatorAbove?: boolean;
}) {
    const t = useT();
    const author = message.author;
    // Trimmed rather than compared to '': an upgraded body may be whitespace.
    const hasBody = message.body.trim() !== '';
    const hasTextAbove = hasBody || Boolean(message.subject);

    return (
        // The id is the scroll anchor "load older" holds while the page grows above it.
        <li
            data-conversation-message-id={message.id}
            className={cn(
                'group px-4 py-1 sm:px-5',
                !grouped && !separatorAbove && 'mt-3',
                // The transition is not conditional on the flag: one arriving with the class would
                // have nothing to animate from, and what fades is the highlight being taken away.
                'transition-colors duration-1000 motion-reduce:transition-none',
                highlighted && 'bg-selected/10',
            )}
        >
            <div className="flex gap-2">
                <div className="w-10 shrink-0">
                    {grouped ? (
                        // `aria-hidden`, because a screen reader is told rather than shown: a folded
                        // row speaks its author and its time below.
                        <span
                            aria-hidden
                            className="block text-right text-xs leading-6 text-muted-foreground tabular-nums opacity-0 transition-opacity group-hover:opacity-100 group-has-[:focus-visible]:opacity-100 motion-reduce:transition-none"
                        >
                            <Timestamp at={message.createdAt} preset="clockTime" />
                        </span>
                    ) : (
                        <Avatar
                            id={author?.id ?? 0}
                            name={author?.name ?? ''}
                            src={author?.imageUrl ?? null}
                            color={author?.avatarColor ?? null}
                            isAi={author?.isAi ?? false}
                            size="md"
                            decorative
                        />
                    )}
                </div>
                <div className="min-w-0 flex-1">
                    {grouped ? (
                        <span className="sr-only">
                            {author?.name ?? t('%Withdrawn_member%')}, <Timestamp at={message.createdAt} preset="clockTime" />
                        </span>
                    ) : (
                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                            {author ? (
                                <Link href={`/member/${author.id}`} className="truncate text-link hover:underline">
                                    {author.name}
                                </Link>
                            ) : (
                                <span className="truncate">{t('%Withdrawn_member%')}</span>
                            )}
                            <AiChip isAi={author?.isAi ?? false} />
                            <Timestamp at={message.createdAt} preset="clockTime" className="shrink-0" />
                        </div>
                    )}
                    {/* h2, because the page title is the h1 and nothing sits between: h3 would skip a
                        rank. */}
                    {message.subject && (
                        <Heading as="h2" variant="section" className="mt-1 break-words">
                            {message.subject}
                        </Heading>
                    )}
                    {/* A message may be nothing but pictures, and an empty paragraph would leave its
                        height behind. */}
                    {hasBody && (
                        <p className={cn('whitespace-pre-wrap break-words', !grouped && 'mt-1')}>
                            <UserText text={message.body} />
                        </p>
                    )}
                    <ImageGrid images={message.images} variant="boxed" className={hasTextAbove ? 'mt-2' : grouped ? undefined : 'mt-1'} />
                    <FileChips files={message.files} className="mt-2" />
                </div>
            </div>
        </li>
    );
}

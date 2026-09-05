import { Link } from '@inertiajs/react';
import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { Timestamp } from '@/components/timestamp';
import { ImageGrid } from '@/components/image-grid';
import { Heading } from '@/components/ui/heading';
import { UserText } from '@/components/user-text';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ConversationMessage } from './types';

export function ConversationMessageRow({
    message,
    highlighted = false,
    separatorAbove = false,
}: {
    message: ConversationMessage;
    highlighted?: boolean;
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
                // Nothing folds in a conversation of two, so every row opens a turn and carries the
                // margin.
                'px-4 py-1 sm:px-5',
                separatorAbove ? undefined : 'mt-3',
                // The transition is not conditional on the flag: one arriving with the class would
                // have nothing to animate from, and what fades is the highlight being taken away.
                'transition-colors duration-1000 motion-reduce:transition-none',
                highlighted && 'bg-selected/10',
            )}
        >
            <div className="flex gap-2">
                <div className="w-10 shrink-0">
                    <Avatar
                        id={author?.id ?? 0}
                        name={author?.name ?? ''}
                        src={author?.imageUrl ?? null}
                        color={author?.avatarColor ?? null}
                        isAi={author?.isAi ?? false}
                        size="md"
                        decorative
                    />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        {author ? (
                            <Link href={`/member/${author.id}`} className="truncate text-link hover:underline">
                                {author.name}
                            </Link>
                        ) : (
                            <span className="truncate">{t('Withdrawn member')}</span>
                        )}
                        <AiChip isAi={author?.isAi ?? false} />
                        <Timestamp at={message.createdAt} preset="clockTime" className="shrink-0" />
                        {/* Only ever on the viewer's own: a message they received is one they are reading. */}
                        {message.read === true && <span className="shrink-0 text-xs">{t('Read (adjective)')}</span>}
                    </div>
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
                        <p className="mt-1 whitespace-pre-wrap break-words">
                            <UserText text={message.body} />
                        </p>
                    )}
                    <ImageGrid images={message.images} variant="boxed" className={hasTextAbove ? 'mt-2' : 'mt-1'} />
                </div>
            </div>
        </li>
    );
}

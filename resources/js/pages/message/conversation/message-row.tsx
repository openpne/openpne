import { Link } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { Timestamp } from '@/components/timestamp';
import { ImageGrid } from '@/components/image-grid';
import { Heading } from '@/components/ui/heading';
import { UserText } from '@/components/user-text';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ConversationMessage } from './types';

/**
 * One message in a conversation. Shaped like a board comment rather than a two-sided bubble stream —
 * the same row a reader already knows from a group's talk, so every conversation on the site is read
 * the same way. A withdrawn author keeps their place with the established label.
 *
 * `highlighted` is a `?m=` link's landing: the row it opened on, held for a moment so the reader can
 * see which message brought them here.
 */
export function ConversationMessageRow({ message, highlighted = false }: { message: ConversationMessage; highlighted?: boolean }) {
    const t = useT();
    const author = message.author;

    return (
        // The id is the scroll anchor "load older" holds while the page grows above it.
        <li
            data-conversation-message-id={message.id}
            className={cn(
                'px-4 py-3 sm:px-5',
                // The transition is not conditional on the flag: what fades is the highlight being
                // taken away, and a transition arriving with the class would have nothing to animate
                // from. The row mounts already highlighted, so the emphasis itself is instant.
                'transition-colors duration-1000 motion-reduce:transition-none',
                highlighted && 'bg-selected/10',
            )}
        >
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Avatar
                    id={author?.id ?? 0}
                    name={author?.name ?? ''}
                    src={author?.imageUrl ?? null}
                    color={author?.avatarColor ?? null}
                    size="md"
                    decorative
                />
                {author ? (
                    <Link href={`/member/${author.id}`} className="truncate text-link hover:underline">
                        {author.name}
                    </Link>
                ) : (
                    <span className="truncate">{t('Withdrawn member')}</span>
                )}
                <Timestamp at={message.createdAt} preset="relative" className="ml-auto shrink-0" />
                {/* Only ever on the viewer's own: a message they received is one they are reading. */}
                {message.read === true && <span className="shrink-0 text-xs">{t('Read (adjective)')}</span>}
            </div>
            {/* Mailbox messages carry a subject and chat ones do not, so it names this message rather
                than repeating the room — which is the heading recipe's job, and the one place weight
                is spent (docs/internals/typography.md). h2, because the page title is the h1 and
                nothing sits between: h3 would skip a rank. */}
            {message.subject && (
                <Heading as="h2" variant="section" className="mt-1 break-words">
                    {message.subject}
                </Heading>
            )}
            <p className="mt-1 whitespace-pre-wrap break-words">
                <UserText text={message.body} />
            </p>
            <ImageGrid images={message.images} variant="boxed" className="mt-2" />
        </li>
    );
}

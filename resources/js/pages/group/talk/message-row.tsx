import { Link } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { Timestamp } from '@/components/timestamp';
import { dangerActionClass } from '@/components/ui/danger-link';
import { UserText } from '@/components/user-text';
import { useT } from '@/lib/i18n';
import type { TalkMessage } from './types';

/**
 * One utterance. Shaped like a board comment rather than a two-sided bubble stream: the same row a
 * reader already knows from topics and events, so a conversation and a thread are read the same way.
 * A withdrawn author keeps their place with the established label — the message stays, the person is
 * gone.
 */
export function TalkMessageRow({ message, onDelete }: { message: TalkMessage; onDelete: (id: number) => void }) {
    const t = useT();
    const author = message.author;

    return (
        // The id is the scroll anchor "load older" holds while the page grows above it.
        <li data-talk-message-id={message.id} className="px-4 py-3 sm:px-5">
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
                {message.canDelete && (
                    <button type="button" onClick={() => onDelete(message.id)} className={`${dangerActionClass} shrink-0`}>
                        {t('Delete')}
                    </button>
                )}
            </div>
            <p className="mt-1 whitespace-pre-wrap break-words">
                <UserText text={message.body} />
            </p>
        </li>
    );
}

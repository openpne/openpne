import { Link } from '@inertiajs/react';
import { Avatar } from '@/components/avatar';
import { Timestamp } from '@/components/timestamp';
import { dangerActionClass } from '@/components/ui/danger-link';
import { EntityText } from '@/components/entity-text';
import { ImageGrid } from '@/components/image-grid';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { TalkMessage } from './types';

/**
 * One utterance. Shaped like a board comment rather than a two-sided bubble stream: the same row a
 * reader already knows from topics and events, so a conversation and a thread are read the same way.
 * A withdrawn author keeps their place with the established label — the message stays, the person is
 * gone.
 *
 * `highlighted` is the deep link's landing: the row a `?m=` link opened on, held for a moment so the
 * reader can see which message named them.
 */
export function TalkMessageRow({
    message,
    onDelete,
    highlighted = false,
}: {
    message: TalkMessage;
    onDelete: (id: number) => void;
    highlighted?: boolean;
}) {
    const t = useT();
    const author = message.author;

    return (
        // The id is the scroll anchor "load older" holds while the page grows above it.
        <li
            data-talk-message-id={message.id}
            className={cn(
                'px-4 py-3 sm:px-5',
                // The transition is not conditional on the flag: what fades is the highlight being
                // taken away, and a transition arriving with the class would have nothing to animate
                // from. The row mounts already highlighted, so the emphasis itself is instant.
                'transition-colors duration-1000 motion-reduce:transition-none',
                // 10%: enough tint to pick the row out, light enough to leave the author link's own
                // contrast over AA (it is 4.48:1 at 15%).
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
                {message.canDelete && (
                    <button type="button" onClick={() => onDelete(message.id)} className={`${dangerActionClass} shrink-0`}>
                        {t('Delete')}
                    </button>
                )}
            </div>
            <p className="mt-1 whitespace-pre-wrap break-words">
                <EntityText text={message.body} mentions={message.mentions} />
            </p>
            <ImageGrid images={message.images} variant="boxed" className="mt-2" />
        </li>
    );
}

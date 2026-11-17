import { AiChip } from '@/components/ai-chip';
import { Avatar } from '@/components/avatar';
import { Timestamp } from '@/components/timestamp';
import { useT } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

type Author = { id: number; name: string; imageUrl: string | null; avatarColor: string | null; isAi: boolean };

type Props = {
    /** `null` for an author who has since withdrawn. */
    author: Author | null;
    href?: string;
    at: string;
    edited?: boolean;
    menu?: ReactNode;
};

/** The name and the date take a line each: at phone width one line cannot hold both beside the menu. */
export function PostByline({ author, href, at, edited = false, menu }: Props) {
    const t = useT();
    const target = author ? href || `/member/${author.id}` : null;
    const avatar = <Avatar id={author?.id ?? 0} name={author?.name ?? ''} src={author?.imageUrl ?? null} color={author?.avatarColor ?? null} isAi={author?.isAi ?? false} size="md" decorative />;

    return (
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
            {target ? (
                // A second way to the same place for a pointer or a finger; the name beside it is the link a keyboard and a screen reader get.
                <Link href={target} tabIndex={-1} aria-hidden className="flex shrink-0">
                    {avatar}
                </Link>
            ) : (
                avatar
            )}
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    {author && target ? (
                        <Link href={target} className="min-w-0 truncate text-link hover:underline">
                            {author.name}
                        </Link>
                    ) : (
                        <span>{t('%Withdrawn_member%')}</span>
                    )}
                    <AiChip isAi={author?.isAi ?? false} />
                </div>
                <div className="text-xs">
                    <Timestamp at={at} preset="absolute" />
                    {edited && <span> ({t('Edited')})</span>}
                </div>
            </div>
            <span className="shrink-0">{menu}</span>
        </div>
    );
}

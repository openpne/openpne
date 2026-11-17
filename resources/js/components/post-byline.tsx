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
    /** Where the name leads; the member's profile unless the page says otherwise. */
    href?: string;
    at: string;
    edited?: boolean;
    menu?: ReactNode;
};

/**
 * The name and the date sit on two lines because one line cannot hold both at phone width: sharing
 * it, the date wrapped and the name was cut short while room remained.
 */
export function PostByline({ author, href, at, edited = false, menu }: Props) {
    const t = useT();

    return (
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Avatar id={author?.id ?? 0} name={author?.name ?? ''} src={author?.imageUrl ?? null} color={author?.avatarColor ?? null} isAi={author?.isAi ?? false} size="md" decorative />
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    {author ? (
                        <Link href={href ?? `/member/${author.id}`} className="min-w-0 truncate text-link hover:underline">
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
            {menu && <span className="shrink-0">{menu}</span>}
        </div>
    );
}

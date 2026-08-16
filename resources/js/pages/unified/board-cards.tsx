import { Link } from '@inertiajs/react';
import { MessageCircle, Users, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { CountBadge } from '@/components/entry-row';
import { useT } from '@/lib/i18n';

export interface BoardCardRow {
    id: number;
    href: string;
    /** What the entry is called — the line that leads the card. */
    name: string;
    /** The date slot: a `<Timestamp>` / `<CivilDate>`, with whatever label the board gives it. */
    date: ReactNode;
    commentCount: number;
    /** Events only; a topic has no roster to count. */
    participantCount?: number;
}

/**
 * A board's latest entries on the group page, title first. The canonical EntryRow leads with the
 * author's face, which is right on a board where the question is who is posting; here the card sits
 * inside a section about one group and the question is what is being discussed, so the title leads
 * and the byline is gone — the same trade the unified layout's diary cards make.
 */
export function BoardCards({ rows, icon: Icon }: { rows: BoardCardRow[]; icon: LucideIcon }) {
    const t = useT();

    return (
        <ul className="space-y-2">
            {rows.map((row) => (
                <li key={row.id}>
                    <Link
                        href={row.href}
                        className="block rounded-xl border border-border px-3.5 py-3 transition-colors hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <div className="flex min-w-0 items-center gap-2">
                            <Icon className="size-4 shrink-0 text-primary" aria-hidden />
                            <span className="min-w-0 flex-1 truncate text-base text-foreground">{row.name}</span>
                            <span className="shrink-0 text-xs text-muted-foreground">{row.date}</span>
                        </div>
                        {(row.commentCount > 0 || (row.participantCount ?? 0) > 0) && (
                            <div className="mt-1 flex items-center gap-3 text-xs text-muted-foreground">
                                <CountBadge
                                    icon={MessageCircle}
                                    count={row.commentCount}
                                    srLabel={t(':count comments', { count: row.commentCount })}
                                />
                                <CountBadge
                                    icon={Users}
                                    count={row.participantCount ?? 0}
                                    srLabel={t(':count participants', { count: row.participantCount ?? 0 })}
                                />
                            </div>
                        )}
                    </Link>
                </li>
            ))}
        </ul>
    );
}

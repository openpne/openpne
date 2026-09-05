import { Link } from '@inertiajs/react';
import { MessageCircle, Users, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { CountBadge } from '@/components/entry-row';
import { useT } from '@/lib/i18n';

export interface BoardCardRow {
    id: number;
    href: string;
    name: string;
    /** The date slot: a `<Timestamp>` / `<CivilDate>`, with whatever label the board gives it. */
    date: ReactNode;
    commentCount: number;
    /** Events only; a topic has no roster to count. */
    participantCount?: number;
}

/**
 * Title-first rather than the canonical EntryRow: inside a section about one group the question is
 * what is being discussed, not who is posting.
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

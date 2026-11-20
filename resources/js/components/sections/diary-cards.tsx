import { Link } from '@inertiajs/react';
import { Timestamp } from '@/components/timestamp';
import { CONTENT_KINDS } from '@/lib/content-kinds';
import type { DiarySummary } from '@/pages/diary/types';

/**
 * Title-first rather than the canonical DiaryRow: on a page entirely about one member the byline
 * says the same thing three times over.
 */
export function DiaryCards({ diaries }: { diaries: DiarySummary[] }) {
    const DiaryIcon = CONTENT_KINDS.diary.icon;

    return (
        <ul className="space-y-2">
            {diaries.map((diary) => (
                <li key={diary.id}>
                    <Link
                        href={`/diary/${diary.id}`}
                        className="block rounded-xl border border-border px-3.5 py-3 transition-colors hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <div className="flex min-w-0 items-center gap-2">
                            <DiaryIcon className="size-4 shrink-0 text-primary" aria-hidden />
                            <span className="min-w-0 flex-1 truncate text-base text-foreground">{diary.title}</span>
                            <Timestamp at={diary.createdAt} preset="listStamp" className="shrink-0 text-xs text-muted-foreground" />
                        </div>
                        {diary.excerpt && <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">{diary.excerpt}</p>}
                    </Link>
                </li>
            ))}
        </ul>
    );
}

import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';

/**
 * The foot of the page: when this day was last put together, and the two things about it a reader
 * would otherwise have to guess.
 *
 * A day is a snapshot taken once, so a post taken down or narrowed afterwards leaves it rather than
 * being rewritten out of it — a reader who followed a link into nothing is owed that sentence. And a
 * day nobody posted produces nothing at all: the page then shows the last day there was, which
 * without a word would read as today and misdate everything on it.
 *
 * The time is the instant this page is about, not a schedule: a day put together by hand did not
 * arrive at the hour a timetable would claim (docs/internals/datetime.md).
 */
export function Colophon({ publishedAt, stale }: { publishedAt: string | null; stale: boolean }) {
    const t = useT();
    const { absolute } = useDateFormat();

    return (
        <footer className="space-y-1 text-xs text-muted-foreground">
            {publishedAt !== null && <p>{t('Updated :time', { time: absolute(publishedAt) })}</p>}
            <p>{t('Posts deleted or made private since are not shown here.')}</p>
            {stale && <p>{t('Nothing new today yet — the next post starts a new day here.')}</p>}
        </footer>
    );
}

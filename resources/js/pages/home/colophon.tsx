import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';

/**
 * The foot of the page: which stretch this issue was drawn from, when it was put together, and the
 * two things about it a reader would otherwise have to guess.
 *
 * The stretch is stated because the masthead names days and a day here is not a calendar day — it
 * runs from one morning's publication to the next (docs/internals/home-issues.md). Without the two
 * instants, a reader seeing yesterday's evening post under yesterday's date has no way to tell
 * whether the page is late or the day is longer than they assumed.
 *
 * A day is a snapshot taken once, so a post taken down or narrowed afterwards leaves it rather than
 * being rewritten out of it — a reader who followed a link into nothing is owed that sentence. And a
 * day nobody posted produces nothing at all: the page then shows the last day there was, which
 * without a word would read as today and misdate everything on it.
 *
 * The time is the instant this page is about, not a schedule: a day put together by hand did not
 * arrive at the hour a timetable would claim (docs/internals/datetime.md).
 */
export function Colophon({
    publishedAt,
    window,
    stale,
}: {
    publishedAt: string | null;
    window: { from: string; to: string } | null;
    stale: boolean;
}) {
    const t = useT();
    const { absolute } = useDateFormat();

    return (
        <footer className="space-y-1 text-xs text-muted-foreground">
            {window !== null && <p>{t('Posts from :from to :to', { from: absolute(window.from), to: absolute(window.to) })}</p>}
            {publishedAt !== null && <p>{t('Updated :time', { time: absolute(publishedAt) })}</p>}
            <p>{t('Posts deleted or made private since are not shown here.')}</p>
            {stale && <p>{t('Nothing new today yet — the next post starts a new day here.')}</p>}
        </footer>
    );
}

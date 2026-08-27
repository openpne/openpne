import { useT } from '@/lib/i18n';

/**
 * The foot of the page: when the next issue runs, and the two things about an issue a reader would
 * otherwise have to guess.
 *
 * An issue is a snapshot taken once, so a post taken down or narrowed after publication leaves the
 * issue rather than being rewritten out of it — a reader who followed a link into nothing is owed
 * that sentence. And a day nobody posted produces no issue at all: the page then shows the last one
 * there was, which without a word would read as today's front page and misdate everything on it.
 */
export function Colophon({ publishTime, stale }: { publishTime: string; stale: boolean }) {
    const t = useT();

    return (
        <footer className="space-y-1 text-xs text-muted-foreground">
            <p>{t('Published daily at :time', { time: publishTime })}</p>
            <p>{t('Posts withdrawn or made private after publication are dropped from the issue.')}</p>
            {stale && <p>{t('No new issue today — the next post will make the next one.')}</p>}
        </footer>
    );
}

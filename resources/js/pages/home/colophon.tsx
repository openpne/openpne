import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';

/**
 * A day here is not a calendar day (docs/internals/home-issues.md, "A day runs 06:00 → 06:00"), and
 * the end of the stretch is when the issue went out, so it is not stated twice. The two lines exist
 * for what the page cannot show: stories taken down since, and a stale issue that would otherwise
 * read as today.
 */
export function Colophon({ window, stale }: { window: { from: string; to: string } | null; stale: boolean }) {
    const t = useT();
    const { absolute } = useDateFormat();

    return (
        <footer className="space-y-1 text-xs text-muted-foreground">
            {window !== null && <p>{t('Posts from :from to :to', { from: absolute(window.from), to: absolute(window.to) })}</p>}
            <p>{t('Posts deleted or made private since are not shown here.')}</p>
            {stale && <p>{t('Nothing new today yet — the next post starts a new day here.')}</p>}
        </footer>
    );
}

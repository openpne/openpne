import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';

/**
 * A day here is not a calendar day (docs/internals/home-issues.md, "A day runs 06:00 → 06:00"), so the
 * first line states the instants the issue was drawn from; the second says what the page cannot show,
 * stories taken down since.
 */
export function Colophon({ window }: { window: { from: string; to: string } }) {
    const t = useT();
    const { absolute } = useDateFormat();

    return (
        <footer className="space-y-1 text-xs text-muted-foreground">
            <p>{t('Posts from :from to :to', { from: absolute(window.from), to: absolute(window.to) })}</p>
            <p>{t('Posts deleted or made private since are not shown here.')}</p>
        </footer>
    );
}

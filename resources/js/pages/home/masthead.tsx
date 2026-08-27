import { CivilDate } from '@/components/timestamp';
import { headingVariants } from '@/components/ui/heading';
import { useT } from '@/lib/i18n';

/**
 * The issue's nameplate: the day it covers and its number, on one line.
 *
 * No brand mark and no site name, unlike the newspaper front pages this borrows from. The chrome
 * already carries both on every width — the brand row on the phone's top bar, the sidebar's lockup
 * on the desktop — and a page that repeated them under its own chrome would say the site's name
 * twice before saying anything about the issue.
 *
 * `label` rank, not a page title: what the reader came for is the story below, and this is the
 * dateline over it. It is still the page's `h1` — the issue is what the screen is — which is why the
 * rank comes from the recipe rather than from a `Heading` of a level that would misstate the
 * document.
 */
export function Masthead({ date, number }: { date: string; number?: number }) {
    const t = useT();

    return (
        <h1 className={`${headingVariants({ variant: 'label' })} flex flex-wrap items-center gap-2 border-b border-border pb-2`}>
            <CivilDate value={date} weekday />
            {number !== undefined && (
                <>
                    <span aria-hidden>·</span>
                    <span>{t('No. :number', { number })}</span>
                </>
            )}
        </h1>
    );
}

import { headingVariants } from '@/components/ui/heading';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';

/**
 * The nameplate: what the page is, said as the day it covers.
 *
 * No brand mark and no site name, unlike the newspaper front pages this borrows from. The chrome
 * already carries both on every width — the brand row on the phone's top bar, the sidebar's lockup
 * on the desktop — and a page that repeated them under its own chrome would say the site's name
 * twice before saying anything about the day.
 *
 * `label` rank, not a page title: what the reader came for is the story below, and this is the
 * dateline over it. It is still the page's `h1` — the day is what the screen is — which is why the
 * rank comes from the recipe rather than from a `Heading` of a level that would misstate the
 * document. The sentence is wrapped in the `<time>` so the machine-readable civil date survives the
 * wording around it.
 */
export function Masthead({ date }: { date: string }) {
    const t = useT();
    const { civilDate } = useDateFormat();

    return (
        <h1 className={`${headingVariants({ variant: 'label' })} border-b border-border pb-2`}>
            <time dateTime={date}>{t('What happened on :date', { date: civilDate(date, true) })}</time>
        </h1>
    );
}

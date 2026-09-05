import { headingVariants } from '@/components/ui/heading';
import { useT } from '@/lib/i18n';
import { useDateFormat } from '@/lib/use-date-format';

/**
 * `label` rank but still the page's `h1`: the day is what the screen is, so the rank comes from the
 * recipe rather than from a `Heading` level that would misstate the document. The `<time>` is the
 * one-day form only, since HTML has no machine-readable range.
 */
export function Masthead({ from, to }: { from: string; to: string }) {
    const t = useT();
    const { civilDate } = useDateFormat();

    return (
        <h1 className={`${headingVariants({ variant: 'label' })} border-b border-border pb-2`}>
            {from === to ? (
                <time dateTime={from}>{t('What happened on :date', { date: civilDate(from, true) })}</time>
            ) : (
                t('What happened from :from to :to', { from: civilDate(from), to: civilDate(to) })
            )}
        </h1>
    );
}

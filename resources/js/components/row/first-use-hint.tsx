import { X } from 'lucide-react';
import { Tip } from '@/components/ui/tooltip';
import { useCoarsePointer } from '@/lib/use-coarse-pointer';
import { useT } from '@/lib/i18n';

/** One line above a list, worded for the pointer in hand; the page owns whether it is drawn. */
export function FirstUseHint({ visible, onDismiss }: { visible: boolean; onDismiss: () => void }) {
    const t = useT();
    const coarse = useCoarsePointer();

    if (!visible) {
        return null;
    }

    return (
        <p className="flex items-center gap-2 rounded-xl border border-border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
            <span className="min-w-0 flex-1">{coarse ? t('Press and hold to react and more.') : t('Hover to react and more.')}</span>
            <Tip label={t('Close')}>
                <button type="button" onClick={onDismiss} className="inline-flex size-8 shrink-0 items-center justify-center rounded-full transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                    <X className="size-4" aria-hidden />
                </button>
            </Tip>
        </p>
    );
}

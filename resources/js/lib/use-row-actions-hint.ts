import { usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { xsrfHeader } from '@/lib/csrf';
import type { PageProps } from '@/types';

/**
 * The first-use hint's state for a page: shown until the member closes it or opens a row's sheet,
 * then gone for good. The write is fire-and-forget: a refused write shows the hint once more next time.
 */
export function useRowActionsHint(): { visible: boolean; dismiss: () => void } {
    const hint = usePage<PageProps>().props.rowActionsHint;
    const [dismissed, setDismissed] = useState(false);
    const visible = hint === 'shown' && !dismissed;

    const dismiss = useCallback(() => {
        if (hint !== 'shown') {
            return;
        }
        setDismissed((already) => {
            if (!already) {
                void fetch('/member/config/row-actions-hint', {
                    method: 'POST',
                    headers: { Accept: 'application/json', ...xsrfHeader() },
                    credentials: 'same-origin',
                }).catch(() => undefined);
            }

            return true;
        });
    }, [hint]);

    return { visible, dismiss };
}

import { usePage } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';
import { xsrfHeader } from '@/lib/csrf';
import type { ReactionsOnPage } from '@/lib/reactions/row';
import type { PageProps } from '@/types';

/**
 * The first-use hint's state for a page: shown until the member closes it, opens a row's sheet or
 * reacts, then gone for good. The write is fire-and-forget: a refused write shows the hint once more next time.
 */
export function useRowActionsHint(): { visible: boolean; dismiss: () => void; learnedFrom: <T extends Pick<ReactionsOnPage, 'toggle'>>(reactions: T) => T } {
    const hint = usePage<PageProps>().props.rowActionsHint;
    const [dismissed, setDismissed] = useState(false);
    const written = useRef(false);
    const visible = hint === 'shown' && !dismissed;

    const dismiss = useCallback(() => {
        if (hint !== 'shown' || written.current) {
            return;
        }
        written.current = true;
        setDismissed(true);
        void fetch('/member/config/row-actions-hint', {
            method: 'POST',
            headers: { Accept: 'application/json', ...xsrfHeader() },
            credentials: 'same-origin',
        }).catch(() => undefined);
    }, [hint]);

    // A reaction made from the bar or the chips is the hint followed, on a cursor as on a finger.
    const learnedFrom = useCallback(
        <T extends Pick<ReactionsOnPage, 'toggle'>>(reactions: T): T => ({
            ...reactions,
            toggle: (id: number, emoji: string, mine: boolean) => {
                dismiss();
                reactions.toggle(id, emoji, mine);
            },
        }),
        [dismiss],
    );

    return { visible, dismiss, learnedFrom };
}

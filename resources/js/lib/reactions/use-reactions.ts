import { useCallback, useEffect, useRef, useState } from 'react';
import { xsrfHeader } from '@/lib/csrf';
import { chipsWithPending, isPending, noPending, withoutPending, withPending, type PendingReactions, type ReactionOp } from './overlay';
import type { ReactionChip } from './types';

export interface ReactionEndpoints {
    add: (id: number) => string;
    remove: (id: number) => string;
    reactors: (id: number) => string;
}

/**
 * Reactions on a page that does not poll: the row the write answers with replaces what the page was
 * rendered with, since nothing else will bring a later change. `resetKey` is the page's own identity
 * — a fresh render carries fresh rows, and an answer kept from before it would be older than them.
 */
export function useReactions(endpoints: ReactionEndpoints, resetKey: unknown) {
    const [pending, setPending] = useState<PendingReactions>(noPending);
    const [answered, setAnswered] = useState<ReadonlyMap<number, ReactionChip[]>>(() => new Map());
    const [reactorsFor, setReactorsFor] = useState<number | null>(null);
    const [reactorsEmoji, setReactorsEmoji] = useState<string | undefined>(undefined);
    // An answer is drawn only past the last one drawn on its row, and neither count resets with the page.
    const sent = useRef(new Map<number, number>());
    const drawn = useRef(new Map<number, number>());

    useEffect(() => {
        setAnswered(new Map());
        setPending(noPending());
        setReactorsFor(null);
        drawn.current = new Map(sent.current);
    }, [resetKey]);

    const chips = useCallback(
        (id: number, rendered: ReactionChip[]): ReactionChip[] => chipsWithPending(answered.get(id) ?? rendered, pending, id),
        [answered, pending],
    );

    const toggle = useCallback(
        (id: number, emoji: string, mine: boolean): void => {
            if (isPending(pending, id, emoji)) {
                return;
            }
            const op: ReactionOp = mine ? 'remove' : 'add';
            setPending((current) => withPending(current, id, emoji, op));
            const ticket = (sent.current.get(id) ?? 0) + 1;
            sent.current.set(id, ticket);

            const settle = () => setPending((current) => withoutPending(current, id, emoji));

            void fetch(op === 'add' ? endpoints.add(id) : endpoints.remove(id), {
                method: 'POST',
                headers: { ...xsrfHeader(), Accept: 'application/json', 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ emoji }),
            })
                .then((response) => (response.ok ? (response.json() as Promise<{ reactions?: ReactionChip[] }>) : null))
                .then((payload) => {
                    // A refusal says nothing to the reader: the guess goes away and the row stands.
                    if (payload?.reactions !== undefined && ticket > (drawn.current.get(id) ?? 0)) {
                        drawn.current.set(id, ticket);
                        const row = payload.reactions;
                        setAnswered((current) => new Map(current).set(id, row));
                    }
                })
                .catch(() => undefined)
                .then(settle);
        },
        [endpoints, pending],
    );

    // Memoized so the dialog's fetch effect does not rerun on every render.
    const closeReactors = useCallback(() => setReactorsFor(null), []);
    const showReactors = useCallback((id: number, emoji?: string) => {
        setReactorsEmoji(emoji);
        setReactorsFor(id);
    }, []);

    return { chips, toggle, reactorsFor, reactorsEmoji, showReactors, closeReactors, reactorsUrl: endpoints.reactors };
}

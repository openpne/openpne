/**
 * Serializes fire-and-forget writes of a single value down to the LAST one asked for. Firing them in
 * parallel lets the responses — and therefore the writes — land out of order, so a quick run through
 * three choices could leave an earlier one stored. While a send is in flight, later calls only
 * replace the pending value; when it settles, the queue sends that final value and nothing in
 * between. A failed send is dropped rather than retried, but never strands the values behind it.
 *
 * Pure scheduling, no I/O of its own, so the ordering guarantee is unit-testable without a DOM.
 */
export function createLastIntentQueue<T>(send: (value: T) => Promise<void>): (value: T) => void {
    let chain: Promise<void> | null = null;
    // Boxed so a legitimately falsy value is still distinguishable from "nothing pending".
    let pending: { value: T } | null = null;

    const drain = async (): Promise<void> => {
        try {
            // No await between the loop test failing and the reset below, so a call arriving mid-drain
            // is either picked up by the next iteration or starts a fresh chain — never dropped.
            while (pending !== null) {
                const { value } = pending;
                pending = null;
                try {
                    await send(value);
                } catch {
                    // Dropped, not retried — but the loop must keep going, and `chain` must not be
                    // left holding a rejected promise nobody awaits.
                }
            }
        } finally {
            chain = null;
        }
    };

    return (value: T) => {
        pending = { value };
        if (chain === null) {
            chain = drain();
        }
    };
}

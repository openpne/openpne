/**
 * Serializes fire-and-forget writes of a single value down to the last one asked for: sent in
 * parallel, the responses — and so the writes — could land out of order and leave an earlier value
 * stored. A failed send is dropped rather than retried, but never strands the values behind it.
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

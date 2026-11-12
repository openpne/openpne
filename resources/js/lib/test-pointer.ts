import { vi } from 'vitest';

/** Answers `(pointer: coarse)` and `(any-hover: none)` the way a phone does; every other query stays unmatched. */
export function stubCoarsePointer(coarse = true) {
    vi.stubGlobal('matchMedia', (query: string) => ({
        media: query,
        matches: coarse && (query === '(pointer: coarse)' || query === '(any-hover: none)'),
        addEventListener: () => {},
        removeEventListener: () => {},
    }));
}

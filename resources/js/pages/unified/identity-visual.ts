import type { CSSProperties } from 'react';

/**
 * The unified surfaces' derived identity color, for an entity that chose none: the deep cuts of the
 * member avatar palette (app/Support/AvatarColor — the white-text-safe half), picked by name hash so
 * an entity keeps its color between visits. Scoped to the experiment on purpose: the shipped
 * InitialBadge stays deliberately achromatic for an unchosen color, and this surface trades that
 * principle for the design's rule that nothing looks unfinished.
 */
const DEEP_PALETTE = ['#dc2626', '#c2410c', '#a16207', '#15803d', '#0e7490', '#2563eb', '#7c3aed', '#db2777'];

export function derivedIdentityColor(name: string): string {
    let hash = 0;
    for (const ch of name) {
        hash = (hash * 31 + (ch.codePointAt(0) ?? 0)) >>> 0;
    }

    // The index is always in range; the fallback satisfies noUncheckedIndexedAccess.
    return DEEP_PALETTE[hash % DEEP_PALETTE.length] ?? '#2563eb';
}

/** A soft diagonal wash of one identity color — the designed stand-in for a missing cover photo. */
export function coverGradientStyle(hex: string): CSSProperties {
    return { backgroundImage: `linear-gradient(135deg, ${hex}b8, ${hex})` };
}

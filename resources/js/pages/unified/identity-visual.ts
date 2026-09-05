import type { CSSProperties } from 'react';

/**
 * The white-text-safe half of the member avatar palette (app/Support/AvatarColor), picked by name
 * hash so an entity keeps its color between visits. Scoped to these surfaces on purpose: the shipped
 * InitialBadge stays achromatic for an unchosen color.
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

export function coverGradientStyle(hex: string): CSSProperties {
    return { backgroundImage: `linear-gradient(135deg, ${hex}b8, ${hex})` };
}

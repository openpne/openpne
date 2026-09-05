/**
 * The server ships ladders without knowing the placement, so the `w` descriptor — a candidate's true
 * intrinsic width — is what lets one ladder serve a 300px post cell and a 160px boxed cell alike
 * (docs/internals/images.md, "A variant is a source, not a box").
 */

/** A fit variant: scaled to sit inside a `box`×`box` square, aspect kept, never enlarged. */
export interface FitSource {
    url: string;
    box: number;
}

/** A crop variant: centre-cropped to the cell's ratio, so `width` is already its intrinsic width. */
export interface CropSource {
    url: string;
    width: number;
}

/** The crop ladders, keyed by cell ratio. Both are absent when the underlying file is gone. */
export interface CropSources {
    tall?: CropSource[]; // 3:4
    wide?: CropSource[]; // 3:2
}

/**
 * A fit variant's intrinsic width is not the box it was asked for, so it is derived from the recorded
 * size — which is why an unknown size yields no `srcset` at all rather than a guessed one.
 */
export function fitSrcSet(sources: FitSource[], width: number | null, height: number | null): string | null {
    if (!width || !height) {
        return null;
    }

    const widths = new Set<number>();
    const candidates: string[] = [];

    for (const source of sources) {
        // The server scales down only, so rungs at or above the source collapse onto one `w`, and
        // two candidates claiming it would leave the browser's pick arbitrary.
        const scale = Math.min(1, source.box / width, source.box / height);
        // max(1, …) mirrors Intervention's proportionalWidth: a 1x4096 sliver scales to a 1px-wide
        // variant, never 0 — and 0w is not a valid descriptor.
        const intrinsic = Math.max(1, Math.round(width * scale));

        if (!widths.has(intrinsic)) {
            widths.add(intrinsic);
            candidates.push(`${source.url} ${intrinsic}w`);
        }
    }

    return candidates.length > 0 ? candidates.join(', ') : null;
}

/** The middle rung: the lone `src` a surface paints when it has no size to derive a `srcset` from. */
export function fitFallbackUrl(sources: FitSource[]): string | null {
    return sources[Math.floor(sources.length / 2)]?.url ?? null;
}

/** A crop is exactly its box wide, so the descriptor is literal. */
export function cropSrcSet(ladder: CropSource[] | undefined): string | null {
    if (!ladder || ladder.length === 0) {
        return null;
    }

    return ladder.map((source) => `${source.url} ${source.width}w`).join(', ');
}

/**
 * Turning an attachment's variant ladders into `srcset` candidates.
 *
 * The server ships ladders without knowing the placement, so the `w` descriptor — a candidate's true
 * intrinsic width — is what lets one ladder serve a 300px post cell and a 160px boxed cell alike.
 * See docs/internals/images.md.
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
 * The `srcset` for a picture drawn at its own shape, or null when there is none to build.
 *
 * A fit variant's intrinsic width is not the box it was asked for, so it is derived from the
 * recorded size — which is why an unknown size (null, or a zero side) yields no `srcset` at all
 * rather than a guessed one.
 */
export function fitSrcSet(sources: FitSource[], width: number | null, height: number | null): string | null {
    if (!width || !height) {
        return null;
    }

    const widths = new Set<number>();
    const candidates: string[] = [];

    for (const source of sources) {
        // The server scales down only, so every box at or above the source returns the source's own
        // width and the rungs collapse onto one descriptor. Two candidates claiming the same `w`
        // leaves the browser's pick arbitrary, so only the first — the cheapest — is offered.
        const scale = Math.min(1, source.box / width, source.box / height);
        const intrinsic = Math.round(width * scale);

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

/** The `srcset` for a fixed-shape cell. A crop is exactly its box wide, so the descriptor is literal. */
export function cropSrcSet(ladder: CropSource[] | undefined): string | null {
    if (!ladder || ladder.length === 0) {
        return null;
    }

    return ladder.map((source) => `${source.url} ${source.width}w`).join(', ');
}

import { useRef, useState } from 'react';
import { Lightbox } from '@/components/lightbox';
import { useT } from '@/lib/i18n';
import { type CropSources, cropSrcSet, type FitSource, fitFallbackUrl, fitSrcSet } from '@/lib/image-sources';
import { cn } from '@/lib/utils';

export interface GridImage {
    id: number;
    url: string; // full bytes (FilePolicy-gated)
    thumbnailUrl: string; // 120px square — Classic's and the digests', not this grid's
    fitSources: FitSource[];
    cropSources: CropSources;
    width: number | null; // rendered size, EXIF applied; null → unknown (docs/internals/images.md)
    height: number | null;
}

export type ImageGridVariant = 'post' | 'boxed';

/**
 * What the placement is about to paint, for the browser's candidate pick. A hero capped by height is
 * narrower than its `sizes` says — no expression can state "the height cap divided by this picture's
 * aspect" — so a tall one over-declares and may fetch one rung high. Bounded, and the alternative is
 * measuring the layout in JS before the first byte is asked for.
 */
// `boxed` is read by link-card.tsx as well: a card's picture is held to the same box, so what is
// true of this row has to stay true there.
export const HERO_SIZES: Record<ImageGridVariant, string> = {
    post: '(min-width: 40rem) 37.5rem, 92vw',
    boxed: 'min(24rem, 92vw)',
};

/**
 * The widest a picture that must stay subordinate to its words may draw: a comment's or a chat row's
 * own picture, and a link card's preview on every surface. Every cap in one width formula — the
 * container, the picture's own size (never enlarged) and the 20rem height limit carried through
 * `ratio` (a CSS expression for width ÷ height) — because a height cap set as `max-height` squashes
 * anything with a non-auto width (see the hero below).
 */
export function boxedPictureMaxWidth(ownWidth: number | null, ratio: string): string {
    return `min(100%, 24rem, ${ownWidth === null ? '' : `${ownWidth}px, `}calc(20rem * (${ratio})))`;
}

const CELL_SIZES: Record<ImageGridVariant, string> = {
    post: '(min-width: 40rem) 18.75rem, 46vw',
    boxed: '12rem',
};

/**
 * A post/comment/message's pictures, opening the shared Lightbox in place (the old behavior jumped to
 * the raw image in a new tab, which strands the reader — especially on mobile).
 *
 * A lone picture is a hero at its own shape: cropping the only thing there is to see, to a square the
 * author never framed, is what made attachments read as filing-cabinet icons. Two or three become one
 * album block, because a row of ragged shapes has no edge for the eye to follow.
 *
 * `variant` is the placement, not a size: `post` is a body's own picture, `boxed` a comment's or a
 * chat row's, which must stay subordinate to the text it sits under.
 */
export function ImageGrid({ images, variant, className }: { images: GridImage[]; variant: ImageGridVariant; className?: string }) {
    const t = useT();
    const [openIndex, setOpenIndex] = useState<number | null>(null);
    const opener = useRef<HTMLButtonElement | null>(null);
    // Where each picture sits on the page, so closing the viewer can put it back there rather than
    // dropping it off an edge. The page is scroll-locked while the viewer is up, so a rect measured
    // on the way out is still the rect the reader last saw.
    const thumbs = useRef<(HTMLImageElement | null)[]>([]);

    if (images.length === 0) {
        return null;
    }

    const openAt = (i: number) => (e: React.MouseEvent<HTMLButtonElement>) => {
        opener.current = e.currentTarget;
        setOpenIndex(i);
    };

    const viewer = (
        <Lightbox
            images={images}
            index={openIndex}
            onClose={() => setOpenIndex(null)}
            onNavigate={setOpenIndex}
            restoreFocus={() => opener.current?.focus()}
            originRect={(i) => thumbs.current[i]?.getBoundingClientRect() ?? null}
        />
    );

    const hero = images.length === 1 ? images[0] : null;

    if (hero) {
        const sized = hero.width !== null && hero.height !== null && hero.width > 0 && hero.height > 0;

        return (
            <>
                <button
                    type="button"
                    onClick={openAt(0)}
                    aria-label={`${t('Image')} 1`}
                    aria-haspopup="dialog"
                    // With the size known, the button IS the picture's box, sized entirely up front:
                    // `aspect-ratio` gives the height, and max-width carries every cap — the container,
                    // the picture's own width (a small source is never stretched), and the height limit
                    // multiplied through the ratio. The height cap must live in the width formula: an
                    // <img> width attribute (or any non-auto width) stays put while max-height clamps,
                    // squashing tall pictures, and the css-sizing-4 max-height→width transfer only
                    // applies to auto widths, which a button resolves as shrink-to-fit, not stretch.
                    // Without a size the <img> sizes itself instead (correct shape, no reservation).
                    className={cn(
                        'block overflow-hidden rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        sized ? 'w-full' : 'w-fit max-w-full',
                        !sized && (variant === 'boxed' ? 'max-h-[20rem]' : 'max-h-[min(70vh,32rem)]'),
                        !sized && variant === 'boxed' && 'max-w-[24rem]',
                        className,
                    )}
                    style={
                        sized
                            ? {
                                  aspectRatio: `${hero.width} / ${hero.height}`,
                                  maxWidth:
                                      variant === 'boxed'
                                          ? boxedPictureMaxWidth(hero.width, `${hero.width} / ${hero.height}`)
                                          : `min(100%, ${hero.width}px, calc(min(70vh, 32rem) * (${hero.width} / ${hero.height})))`,
                              }
                            : undefined
                    }
                >
                    <img
                        ref={(el) => {
                            thumbs.current[0] = el;
                        }}
                        src={fitFallbackUrl(hero.fitSources) ?? ''}
                        srcSet={fitSrcSet(hero.fitSources, hero.width, hero.height) ?? undefined}
                        sizes={HERO_SIZES[variant]}
                        width={hero.width ?? undefined}
                        height={hero.height ?? undefined}
                        alt=""
                        className={cn(
                            sized
                                ? 'h-full w-full object-cover'
                                : cn(
                                      'h-auto max-w-full rounded-lg',
                                      variant === 'boxed' ? 'max-h-[20rem]' : 'max-h-[min(70vh,32rem)]',
                                  ),
                        )}
                    />
                </button>
                {viewer}
            </>
        );
    }

    // Two halves at 3:4, or a 3:4 beside two stacked 3:2s — either way one 3:2 block, so a feed row
    // reserves the same shape whichever it is. Past three only arrives from migrated posts (the
    // composer caps at three), which wrap as 3:2 pairs rather than claim a block shape of their own.
    const three = images.length === 3;
    const wrapped = images.length > 3;

    return (
        <>
            <ul
                className={cn(
                    'grid w-full grid-cols-2 gap-0.5 overflow-hidden rounded-lg',
                    !wrapped && 'aspect-[3/2]',
                    three && 'grid-rows-2',
                    variant === 'boxed' && 'max-w-[24rem]',
                    className,
                )}
            >
                {images.map((image, i) => {
                    const tall = !wrapped && (!three || i === 0);
                    const ladder = tall ? image.cropSources.tall : image.cropSources.wide;

                    return (
                        <li key={image.id} className={cn(three && i === 0 && 'row-span-2', wrapped && 'aspect-[3/2]')}>
                            <button
                                type="button"
                                onClick={openAt(i)}
                                aria-label={`${t('Image')} ${i + 1}`}
                                aria-haspopup="dialog"
                                // Inset, because the grid clips its own rounded corners.
                                className="block h-full w-full focus-visible:inset-ring-2 focus-visible:inset-ring-ring focus-visible:outline-none"
                            >
                                <img
                                    ref={(el) => {
                                        thumbs.current[i] = el;
                                    }}
                                    // A cell whose file is gone has no crop ladder; the fit one is a
                                    // better nothing than a broken image.
                                    src={ladder?.[0]?.url ?? fitFallbackUrl(image.fitSources) ?? ''}
                                    srcSet={cropSrcSet(ladder) ?? undefined}
                                    sizes={CELL_SIZES[variant]}
                                    alt=""
                                    className="h-full w-full object-cover"
                                />
                            </button>
                        </li>
                    );
                })}
            </ul>
            {viewer}
        </>
    );
}

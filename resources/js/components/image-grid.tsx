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
 * A hero capped by height is narrower than its `sizes` says — no expression can state the height cap
 * divided by this picture's aspect — so a tall one over-declares and may fetch one rung high.
 */
// `boxed` is read by link-card.tsx too, where the card's own padding makes this over-declare by at
// most one rung, and under-declaring would cost a blurry pick.
export const HERO_SIZES: Record<ImageGridVariant, string> = {
    post: '(min-width: 40rem) 37.5rem, 92vw',
    boxed: 'min(24rem, 92vw)',
};

/**
 * Every cap in one width formula — the container, the picture's own size and the 20rem height limit
 * carried through `ratio` — because a height cap set as `max-height` squashes anything with a
 * non-auto width.
 */
export function boxedPictureMaxWidth(ownWidth: number | null, ratio: string): string {
    return `min(100%, 24rem, ${ownWidth === null ? '' : `${ownWidth}px, `}calc(20rem * (${ratio})))`;
}

const CELL_SIZES: Record<ImageGridVariant, string> = {
    post: '(min-width: 40rem) 18.75rem, 46vw',
    boxed: '12rem',
};

/**
 * `variant` is the placement, not a size: `post` is a body's own picture, `boxed` a comment's or a
 * chat row's, which must stay subordinate to the text it sits under.
 */
export function ImageGrid({ images, variant, className }: { images: GridImage[]; variant: ImageGridVariant; className?: string }) {
    const t = useT();
    const [openIndex, setOpenIndex] = useState<number | null>(null);
    const opener = useRef<HTMLButtonElement | null>(null);
    // The page is scroll-locked while the viewer is up, so a rect measured on the way out is still
    // the rect the reader last saw, and closing can put the picture back where it stands.
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
                    // With the size known the button is the picture's box, so the height cap has to
                    // live in the width formula: the css-sizing-4 max-height→width transfer applies
                    // only to auto widths, and a button resolves as shrink-to-fit.
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

    // Either arrangement is one 3:2 block, so a feed row reserves the same shape; past three only
    // arrives from migrated posts and wraps as 3:2 pairs instead.
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

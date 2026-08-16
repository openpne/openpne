import { Link } from '@inertiajs/react';
import type { GridImage } from '@/components/image-grid';
import { useT } from '@/lib/i18n';
import { fitFallbackUrl, fitSrcSet } from '@/lib/image-sources';
import { cn } from '@/lib/utils';

export interface HomePhoto {
    /** Which kind of content the picture is attached to — what the tile's link announces. */
    source: 'diary' | 'timeline';
    href: string;
    image: GridImage;
}

/** How many pictures lead the mosaic at the wider shape. */
const LEAD = 2;

const LEAD_SIZES = '(min-width: 40rem) 18.75rem, 46vw';
const TILE_SIZES = '(min-width: 40rem) 12rem, 31vw';

/**
 * The viewer's latest pictures, newest first. A tile opens the content the picture was posted with
 * rather than the picture alone: there is no gallery here, only a shorter way back into what it
 * illustrates.
 *
 * The newest two are given the wider shape and the rest fall into thirds, so the block reads as
 * "here is what you just posted, and here is the while before it" rather than as an undifferentiated
 * contact sheet. One list at six columns rather than two lists: the pictures are one sequence, and a
 * span is all that separates the two shapes.
 */
export function PhotoGrid({ photos }: { photos: HomePhoto[] }) {
    const t = useT();

    return (
        <ul className="grid grid-cols-6 gap-1.5">
            {photos.map(({ source, href, image }, index) => {
                const lead = index < LEAD;

                return (
                    <li key={`${source}-${image.id}`} className={lead ? 'col-span-3' : 'col-span-2'}>
                        <Link
                            href={href}
                            // The picture is decorative, so the link needs a name of its own.
                            aria-label={source === 'diary' ? t('View this %diary%') : t('View this %post_activity%')}
                            className={cn(
                                'block overflow-hidden rounded-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                lead ? 'aspect-[4/3]' : 'aspect-square',
                            )}
                        >
                            <img
                                // The fit ladder with a CSS crop: the cell shape is this page's own,
                                // not one the server cuts variants for.
                                src={fitFallbackUrl(image.fitSources) ?? image.thumbnailUrl}
                                srcSet={fitSrcSet(image.fitSources, image.width, image.height) ?? undefined}
                                sizes={lead ? LEAD_SIZES : TILE_SIZES}
                                alt=""
                                className="h-full w-full object-cover"
                            />
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}

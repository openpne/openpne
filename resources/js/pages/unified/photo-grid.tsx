import { Link } from '@inertiajs/react';
import type { GridImage } from '@/components/image-grid';
import { useT } from '@/lib/i18n';
import { fitFallbackUrl, fitSrcSet } from '@/lib/image-sources';

export interface HomePhoto {
    /** Which kind of content the picture is attached to — what the tile's link announces. */
    source: 'diary' | 'timeline';
    href: string;
    image: GridImage;
}

/** Three tiles across at every width, so the cell is roughly a third of the content column. */
const TILE_SIZES = '(min-width: 40rem) 12rem, 31vw';

/**
 * The viewer's latest pictures, newest first. A tile opens the content the picture was posted with
 * rather than the picture alone: there is no gallery here, only a shorter way back into what it
 * illustrates.
 */
export function PhotoGrid({ photos }: { photos: HomePhoto[] }) {
    const t = useT();

    return (
        <ul className="grid grid-cols-3 gap-1">
            {photos.map(({ source, href, image }) => (
                <li key={`${source}-${image.id}`}>
                    <Link
                        href={href}
                        // The picture is decorative, so the link needs a name of its own.
                        aria-label={source === 'diary' ? t('View this %diary%') : t('View this %post_activity%')}
                        className="block aspect-square overflow-hidden rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <img
                            // The fit ladder with a CSS crop: the square cell is this page's own shape,
                            // not one the server cuts variants for.
                            src={fitFallbackUrl(image.fitSources) ?? image.thumbnailUrl}
                            srcSet={fitSrcSet(image.fitSources, image.width, image.height) ?? undefined}
                            sizes={TILE_SIZES}
                            alt=""
                            className="h-full w-full object-cover"
                        />
                    </Link>
                </li>
            ))}
        </ul>
    );
}

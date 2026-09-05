import { Link } from '@inertiajs/react';
import type { GridImage } from '@/components/image-grid';
import { useT } from '@/lib/i18n';
import { fitFallbackUrl, fitSrcSet } from '@/lib/image-sources';
import { cn } from '@/lib/utils';

export interface HomePhoto {
    /** Which kind of content the picture is attached to — what the tile's link announces. */
    source: 'diary' | 'timeline' | 'talk' | 'topic' | 'event';
    href: string;
    image: GridImage;
}

const LEAD = 2;

const LEAD_SIZES = '(min-width: 40rem) 18.75rem, 46vw';
const TILE_SIZES = '(min-width: 40rem) 12rem, 31vw';

/**
 * A tile opens the content the picture was posted with rather than the picture alone: there is no
 * gallery here. One list at six columns rather than two: the pictures are one sequence, and a span
 * is all that separates the two shapes.
 */
export function PhotoGrid({ photos }: { photos: HomePhoto[] }) {
    const t = useT();

    // The picture is decorative, so the link needs a name of its own: what it opens.
    const linkName: Record<HomePhoto['source'], string> = {
        diary: t('View this %diary%'),
        timeline: t('View this %post_activity%'),
        talk: t('View this message'),
        topic: t('View this %topic%'),
        event: t('View this event'),
    };

    return (
        <ul className="grid grid-cols-6 gap-1.5">
            {photos.map(({ source, href, image }, index) => {
                const lead = index < LEAD;

                return (
                    <li key={`${source}-${image.id}`} className={lead ? 'col-span-3' : 'col-span-2'}>
                        <Link
                            href={href}
                            aria-label={linkName[source]}
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

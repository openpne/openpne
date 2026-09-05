import type { GridImage } from '@/components/image-grid';
import { fitFallbackUrl, fitSrcSet } from '@/lib/image-sources';
import { cn } from '@/lib/utils';

/** The box a strip picture is painted into, so the browser can pick before the layout is measured. */
const PICTURE_SIZES = '6rem';

/** Past three the strip stops being a glimpse and becomes an album. */
const PICTURES = 3;

/**
 * Decorative throughout: whatever the pictures hang under already carries the link that says what
 * they are. A square crops rather than fits, which the fit ladder still serves — the `w` descriptors
 * are the candidates' own widths, not the box's.
 */
export function PictureStrip({ images, className }: { images: GridImage[]; className?: string }) {
    const pictures = images.slice(0, PICTURES);

    if (pictures.length === 0) {
        return null;
    }

    return (
        // Not a list: a row of decorative pictures announces as "list, 3 items" with nothing in any
        // of them, which is noise rather than structure.
        <div className={cn('flex flex-wrap gap-1', className)}>
            {pictures.map((image) => (
                <div key={image.id} className="size-24 overflow-hidden rounded-lg bg-muted">
                    <img
                        src={fitFallbackUrl(image.fitSources) ?? image.thumbnailUrl}
                        srcSet={fitSrcSet(image.fitSources, image.width, image.height) ?? undefined}
                        sizes={PICTURE_SIZES}
                        alt=""
                        loading="lazy"
                        className="size-full object-cover"
                    />
                </div>
            ))}
        </div>
    );
}

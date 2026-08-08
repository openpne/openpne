import { useRef, useState } from 'react';
import { Lightbox } from '@/components/lightbox';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface GridImage {
    id: number;
    url: string;
    thumbnailUrl: string;
}

/**
 * Thumbnail strip for a post/comment/message's images. A thumbnail opens the shared
 * Lightbox in place (the old behavior jumped to the raw image in a new tab, which
 * strands the reader — especially on mobile). `size` bounds the thumbnails; omit it
 * to render them at their served size.
 */
export function ImageGrid({ images, size, className }: { images: GridImage[]; size?: string; className?: string }) {
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

    return (
        <>
            <ul className={cn('flex flex-wrap gap-2', className)}>
                {images.map((image, i) => (
                    <li key={image.id}>
                        <button
                            type="button"
                            onClick={(e) => {
                                opener.current = e.currentTarget;
                                setOpenIndex(i);
                            }}
                            aria-label={`${t('Image')} ${i + 1}`}
                            aria-haspopup="dialog"
                            className="block rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <img
                                ref={(el) => {
                                    thumbs.current[i] = el;
                                }}
                                src={image.thumbnailUrl}
                                alt=""
                                className={cn(size, 'rounded-md object-cover')}
                            />
                        </button>
                    </li>
                ))}
            </ul>
            <Lightbox
                images={images}
                index={openIndex}
                onClose={() => setOpenIndex(null)}
                onNavigate={setOpenIndex}
                restoreFocus={() => opener.current?.focus()}
                originRect={(i) => thumbs.current[i]?.getBoundingClientRect() ?? null}
            />
        </>
    );
}

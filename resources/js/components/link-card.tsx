import { boxedPictureMaxWidth, HERO_SIZES } from '@/components/image-grid';
import { type FitSource, fitFallbackUrl, fitSrcSet } from '@/lib/image-sources';
import { cn } from '@/lib/utils';

export interface LinkCardData {
    url: string;
    title: string;
    description: string | null;
    siteName: string | null;
    domain: string;
    /**
     * Which shape the picture asks for — decided server-side, see
     * App\Models\LinkCard::hasLargeImage.
     */
    layout: 'wide' | 'compact';
    /** The square thumbnail the compact shape draws. */
    imageUrl: string | null;
    /** The size the picture renders at, for the `w` descriptors and the no-enlarging cap. */
    imageWidth: number | null;
    imageHeight: number | null;
    /** The fit ladder the wide shape draws from; empty for a compact card. */
    fitSources: FitSource[];
}

/** What a wide card's picture is cut to. 1200×630 is the shape pages publish, so most are uncut. */
const BANNER_RATIO = 1.91;

/** See docs/internals/link-cards.md, "Drawing it". */
export function LinkCard({ card, className }: { card: LinkCardData | null; className?: string }) {
    if (card === null) {
        return null;
    }

    const frame = cn(
        'overflow-hidden rounded-lg border border-border no-underline transition-colors hover:bg-accent/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
        className,
    );

    const host = <p className="truncate text-xs text-muted-foreground">{card.domain}</p>;
    const title = <p className="mt-1 line-clamp-2 break-words text-foreground">{card.title}</p>;

    if (card.layout === 'wide' && card.fitSources.length > 0) {
        return (
            <a href={card.url} target="_blank" rel="noopener noreferrer nofollow" className={cn('block', frame)}>
                <div className="min-w-0 p-3">
                    {host}
                    {title}
                    {card.description && <p className="mt-1 line-clamp-2 text-sm break-words text-muted-foreground">{card.description}</p>}
                    <img
                        src={fitFallbackUrl(card.fitSources) ?? undefined}
                        srcSet={fitSrcSet(card.fitSources, card.imageWidth, card.imageHeight) ?? undefined}
                        sizes={HERO_SIZES.boxed}
                        alt=""
                        aria-hidden
                        loading="lazy"
                        className="mt-2 block w-full rounded-md bg-muted object-cover"
                        style={{ aspectRatio: `${BANNER_RATIO}`, maxWidth: boxedPictureMaxWidth(card.imageWidth, `${BANNER_RATIO}`) }}
                    />
                </div>
            </a>
        );
    }

    return (
        <a href={card.url} target="_blank" rel="noopener noreferrer nofollow" className={cn('flex', frame)}>
            {card.imageUrl && (
                // Decorative: the title and host beside it already name the destination.

                // The box is no longer square, so the server's square crop and this `cover` compose,
                // taking another 17% of the width — accepted against a per-placement crop variant for
                // a decorative thumbnail.
                <img
                    src={card.imageUrl}
                    alt=""
                    aria-hidden
                    loading="lazy"
                    className="w-24 shrink-0 self-stretch bg-muted object-cover sm:w-30"
                />
            )}
            <div className="min-w-0 flex-1 p-3">
                {host}
                {title}
                {card.description && <p className="mt-1 line-clamp-1 text-sm break-words text-muted-foreground">{card.description}</p>}
            </div>
        </a>
    );
}

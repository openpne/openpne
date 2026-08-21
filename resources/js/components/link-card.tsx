import { HERO_SIZES } from '@/components/image-grid';
import { type FitSource, fitFallbackUrl, fitSrcSet } from '@/lib/image-sources';
import { cn } from '@/lib/utils';

export interface LinkCardData {
    url: string;
    title: string;
    description: string | null;
    siteName: string | null;
    domain: string;
    /** Which shape the picture asks for — decided server-side, see App\Models\LinkCard::hasLargeImage. */
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

/**
 * A preview of the first link in a body: what the page says it is, so a reader can tell without
 * opening it.
 *
 * Everything here is text the linked page chose, escaped by React like any other string — the card
 * is deliberately not part of the body HTML (see docs/internals/link-cards.md). The picture is
 * served from this site, addressed through the post it appears under, so loading a card never tells
 * the linked site who is reading.
 *
 * **The host is always shown, and shown first.** A title is written by the page being linked and can
 * claim to be anyone; the host is the one part of a card that cannot misstate where the link goes,
 * so the reader gets it before the claim rather than after it. It is the host from the URL, never
 * `og:site_name` — that is the page naming itself, which is exactly the thing that can lie.
 *
 * **Two shapes, chosen by the picture** (`layout`): a big landscape image is a preview and is drawn
 * across the card under the words, a small or square one is an icon and sits beside them. Every chat
 * and feed client that draws these makes the same split, and the reason is that neither shape
 * survives the other's picture — a 64px logo blown across the card, or a magazine cover shrunk into
 * a corner.
 *
 * **A card is as wide as the words it belongs to** — it carries no width of its own. Capped at what
 * a surface gives a *picture* instead, it stopped short of the text above it: 384px of card under
 * 550px of message, with the title wrapping and clipping inside the narrower box. A card is a
 * restatement of something in the body, so the body's column is its width, and every surface then
 * needs no number.
 */
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
                </div>
                {/* Cut to the banner shape rather than kept at its own: a card's picture is the
                    linked page's furniture, not a member's framing of their own photo, and a fixed
                    ratio is what keeps a list of cards from ragging. The width cap is the source's
                    own size, so a picture is never enlarged — a narrower one is centred instead. */}
                <img
                    src={fitFallbackUrl(card.fitSources) ?? undefined}
                    srcSet={fitSrcSet(card.fitSources, card.imageWidth, card.imageHeight) ?? undefined}
                    // The card fills its column, and the widest column any surface gives it is the
                    // one a post's own picture declares. A conversation's is narrower, so this
                    // over-declares there by under a tenth — which costs at most one rung too large,
                    // where under-declaring costs a blurry pick (see image-grid's own note).
                    sizes={HERO_SIZES.post}
                    alt=""
                    aria-hidden
                    loading="lazy"
                    className="mx-auto block w-full bg-muted object-cover"
                    style={{ aspectRatio: `${BANNER_RATIO}`, maxWidth: card.imageWidth ? `${card.imageWidth}px` : undefined }}
                />
            </a>
        );
    }

    return (
        <a href={card.url} target="_blank" rel="noopener noreferrer nofollow" className={cn('flex', frame)}>
            {card.imageUrl && (
                // Decorative: the title and host beside it already name the destination, so a screen
                // reader announcing the picture would only repeat them.
                //
                // Fixed in width and stretched in height, so the picture is exactly as tall as the
                // words beside it and leaves no gap under itself. That only stays a shrink — never a
                // blurry enlargement — because the text block cannot outgrow the stored 120px square:
                // a two-line title, one line of description and the host come to 116px.
                //
                // The box is no longer square, so the server's square crop and this `cover` compose:
                // at 96×116 another 17% of the width goes. Small against a gap that was 42px, and the
                // alternative is a cell-ratio crop variant per placement for a decorative thumbnail.
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

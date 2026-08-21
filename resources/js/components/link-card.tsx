import { cn } from '@/lib/utils';

export interface LinkCardData {
    url: string;
    title: string;
    description: string | null;
    siteName: string | null;
    domain: string;
    imageUrl: string | null;
}

/**
 * A preview of the first link in a body: what the page says it is, so a reader can tell without
 * opening it.
 *
 * Everything here is text the linked page chose, escaped by React like any other string — the card
 * is deliberately not part of the body HTML (see docs/internals/link-cards.md). The picture is
 * served from this site, addressed through the post it appears under, so loading a card never tells
 * the linked site who is reading.
 *
 * The domain is shown last and always, including when a title is present: a title is written by the
 * page being linked and can claim to be anyone, so the reader gets the one part of the card that
 * cannot lie about where the link goes.
 */
export function LinkCard({ card, className }: { card: LinkCardData | null; className?: string }) {
    if (card === null) {
        return null;
    }

    return (
        <a
            href={card.url}
            target="_blank"
            rel="noopener noreferrer nofollow"
            className={cn(
                'flex overflow-hidden rounded-lg border border-border no-underline transition-colors hover:bg-accent/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                className,
            )}
        >
            {card.imageUrl && (
                // Decorative: the title and domain beside it already name the destination, so a
                // screen reader announcing the picture would only repeat them.
                <img
                    src={card.imageUrl}
                    alt=""
                    aria-hidden
                    width={120}
                    height={120}
                    loading="lazy"
                    // The intrinsic size is 120 square; width/height are the drawn size, not the
                    // stored one, so the space is reserved correctly before the bytes arrive.
                    className="size-24 shrink-0 bg-muted object-cover sm:size-30"
                />
            )}
            <div className="min-w-0 flex-1 p-3">
                <p className="line-clamp-2 break-words text-foreground">{card.title}</p>
                {card.description && <p className="mt-1 line-clamp-2 text-sm break-words text-muted-foreground">{card.description}</p>}
                <p className="mt-1 truncate text-xs text-muted-foreground">{card.domain}</p>
            </div>
        </a>
    );
}

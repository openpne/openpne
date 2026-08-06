<?php

declare(strict_types=1);

namespace App\LinkCard;

/**
 * What a page said about itself: the fields a card is drawn from.
 *
 * Every string here came from a stranger's markup, so it is stored as text and rendered as text —
 * nothing in this object is HTML. `imageUrl` and `oembedUrl` are absolute (resolved against the
 * response URL by the extractor) but not yet validated as fetchable; that is the fetcher's job.
 */
final readonly class LinkMetadata
{
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?string $siteName = null,
        public ?string $authorName = null,
        public ?string $imageUrl = null,
        public ?string $oembedUrl = null,
    ) {}

    /** Whether anything worth showing was found. A card with no title is not worth drawing. */
    public function isUsable(): bool
    {
        return ($this->title ?? '') !== '';
    }

    /**
     * This metadata with $other filling in only the fields still missing.
     *
     * Used to layer an oEmbed response under what the HTML already said, so the extra request can
     * add a title or a thumbnail but never overwrite the page's own answer.
     */
    public function completedWith(self $other): self
    {
        return new self(
            title: $this->title ?? $other->title,
            description: $this->description ?? $other->description,
            siteName: $this->siteName ?? $other->siteName,
            authorName: $this->authorName ?? $other->authorName,
            imageUrl: $this->imageUrl ?? $other->imageUrl,
            oembedUrl: $this->oembedUrl ?? $other->oembedUrl,
        );
    }
}

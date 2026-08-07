<?php

declare(strict_types=1);

namespace App\LinkCard;

/**
 * What a page said about itself: the fields a card is drawn from.
 *
 * Every string here came from a stranger's markup or a provider's JSON, so it is stored as text and
 * rendered as text — nothing in this object is HTML. `imageUrl` and `oembedUrl` are absolute but not
 * yet validated as fetchable; that is the fetcher's job.
 *
 * **Cleaning and length limits live in the constructor, not in the callers.** These values land in
 * sized columns, so a source that forgot to trim would fail the insert under MySQL strict mode — and
 * there is more than one source (page markup, oEmbed). Putting the rule here makes it impossible for
 * a new one to arrive without it.
 */
final readonly class LinkMetadata
{
    /** Matches the `link_cards` column widths. */
    private const LIMITS = ['title' => 300, 'description' => 500, 'siteName' => 100, 'authorName' => 100];

    public ?string $title;

    public ?string $description;

    public ?string $siteName;

    public ?string $authorName;

    public function __construct(
        ?string $title = null,
        ?string $description = null,
        ?string $siteName = null,
        ?string $authorName = null,
        public ?string $imageUrl = null,
        public ?string $oembedUrl = null,
    ) {
        $this->title = self::clean($title, self::LIMITS['title']);
        $this->description = self::clean($description, self::LIMITS['description']);
        $this->siteName = self::clean($siteName, self::LIMITS['siteName']);
        $this->authorName = self::clean($authorName, self::LIMITS['authorName']);
    }

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

    /**
     * Collapse whitespace, drop control characters, cut to the column width.
     *
     * The control-character strip is not cosmetic: these values reach mail subjects and log lines as
     * well as the page, where a stray newline is a header-injection shape.
     */
    private static function clean(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) preg_replace('/[\p{Cc}\p{Cf}]/u', ' ', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }
}

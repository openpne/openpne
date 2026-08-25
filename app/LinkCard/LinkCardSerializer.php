<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Models\LinkCard;
use App\Models\Member;
use App\Support\LinkCardStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * The card a body renders, as data.
 *
 * Both surfaces read this one shape. Classic could have assembled its own from the model and drifted
 * quietly — a description shown on one surface and not the other, a domain spelled two ways — and
 * more importantly the *gates* would have drifted: whether the setting is on, whether the card has
 * enough to draw, and which URL its picture is served from are all decided here, once.
 *
 * Nothing in the result is HTML. A card is drawn from a page we do not control, so every field
 * arrives at a template as text to be escaped ([body-text.md](../../docs/internals/body-text.md)).
 */
final class LinkCardSerializer
{
    /** The thumbnail edge, in CSS pixels — matched by the whitelisted image geometry. */
    private const THUMBNAIL = 120;

    /**
     * Boxes for the full-width picture, the same fit ladder a post's own images ship
     * (App\Features\Timeline\Serializers\TimelinePostSerializer::image). Every one is already in
     * `openpne.images.allowed_sizes`, and fit scales down only — so a box above the source collapses
     * onto the source's own width, which is why the client derives the `w` descriptors from the
     * recorded size rather than from the box (resources/js/lib/image-sources.ts).
     */
    private const FIT_BOXES = [320, 640, 1200];

    /**
     * $record's card, or null when there is nothing to draw.
     *
     * $viewer is passed rather than read off the request because a card of one of this site's own
     * pages is built from a record the reader may not be allowed to see, and there is no default
     * that is right for both a web-public page and a queued job. No convenience fallback: a call
     * site that forgets it fails loudly instead of quietly serving the card to a guest.
     *
     * @return array{url: string, title: string, description: string|null, siteName: string|null, domain: string, layout: string, imageUrl: string|null, imageWidth: int|null, imageHeight: int|null, fitSources: list<array{url: string, box: int}>}|null
     */
    public static function card(Model $record, ?Member $viewer): ?array
    {
        $kind = CardContext::forRecord($record);
        $card = $record->getAttribute('link_card_id') === null ? null : $record->getRelationValue('linkCard');

        if ($kind === null || ! $card instanceof LinkCard) {
            return null;
        }

        // Before both gates below, and neither applies to it: an internal row is never renderable —
        // it holds no metadata to render — and the setting it would be tested against governs
        // fetching, which this card did not need.
        if ($card->status === LinkCardStatus::Internal) {
            return self::internalCard($card, $viewer);
        }

        if (! $card->isRenderable() || ! app(LinkCardSettings::class)->enabled()) {
            return null;
        }

        $wide = $card->hasLargeImage();

        return [
            'url' => $card->url,
            'title' => (string) $card->title,
            'description' => $card->description,
            'siteName' => $card->site_name,
            'domain' => self::domain($card->url),
            // Which of the two shapes to draw, decided here so the two renderers cannot disagree —
            // the same reason the gates below are not restated per surface. See CardLayout.
            'layout' => ($wide ? CardLayout::Wide : CardLayout::Compact)->value,
            // Never the file's own URL: a card is shared by every body mentioning the link, so the
            // address has to name this record for the request to be authorised against it.
            'imageUrl' => CardContext::imageUrl($record, self::THUMBNAIL, self::THUMBNAIL, square: true),
            // The size the bytes *render* at, from the File rather than from the card row: the card's
            // own columns are what the container declared, read before decoding as part of the size
            // guard, and a sideways-shot JPEG declares its sides the other way round
            // (App\Files\ImageDimensions). Shipped for the reserved aspect box and the `w`
            // descriptors, which is not the same thing as the size it is drawn at.
            'imageWidth' => $card->image?->width,
            'imageHeight' => $card->image?->height,
            // Only the full-width shape asks for these; the thumbnail above is a fixed square.
            'fitSources' => $wide ? self::fitSources($record) : [],
        ];
    }

    /**
     * A card of one of this site's own pages, assembled from the record it names.
     *
     * Nothing is read from the row but the URL and the pointer. What such a card says depends on who
     * is asking, and one row is shared by every body that mentions the URL, so caching any of it
     * would be caching one reader's answer for everyone.
     *
     * The order of the tests is the design:
     *
     *  1. **The URL is read again**, and the pointer must still agree with it. A card is drawn beside
     *     its own `url`, which is what the reader clicks; if this site's address has changed, that
     *     link now leads somewhere else, and describing it with the record the pointer names would be
     *     describing one page while linking to another. It also refuses the row the rename leaves
     *     behind at the old host, which someone else may now answer for.
     *  2. **The unit**, before the record is loaded — as `LinkCardImageController` does, and for the
     *     same reason: an operator switching diaries off has to take their previews with them.
     *  3. **The record's own access rule**, never a copy of it.
     *
     * Everything that fails answers null, so a record that is gone, one the reader may not see, and a
     * row whose pointer is missing or names a kind this app no longer has all read alike — as a link
     * with no card, which is what the body says on its own.
     *
     * @return array{url: string, title: string, description: string|null, siteName: string|null, domain: string, layout: string, imageUrl: string|null, imageWidth: int|null, imageHeight: int|null, fitSources: list<array{url: string, box: int}>}|null
     */
    private static function internalCard(LinkCard $card, ?Member $viewer): ?array
    {
        $link = InternalUrl::of($card->url);
        $target = $link->target;

        // A target is only ever set for our own host, so this one test covers both halves: an
        // address that has stopped being ours resolves to nothing, and so does one of ours that
        // names no record — the state a row of an OpenPNE 3 spelling is left in.
        if ($target === null) {
            return null;
        }

        if ($card->internal_context !== $target->value || $card->internal_record_id !== $link->recordId) {
            return null;
        }

        $feature = $target->feature();

        if ($feature !== null && ! $feature->enabled()) {
            return null;
        }

        $record = app(InternalCardResolver::class)->find($target, $link->recordId);

        if ($record === null || ! $target->canView($record, $viewer)) {
            return null;
        }

        $content = $target->content($record);

        if ($content === null) {
            return null;
        }

        $file = $content['image'];
        $layout = CardLayout::forImage($file?->width, $file?->height);

        return [
            'url' => $card->url,
            'title' => $content['title'],
            'description' => $content['description'],
            // A page of this site does not introduce itself by name: the host is beside the title
            // already, and neither surface draws this field.
            'siteName' => null,
            'domain' => self::domain($card->url),
            'layout' => $layout->value,
            // The record's own picture at its own address, so `FilePolicy` authorises the bytes
            // against the same record whose rule admitted this card. The `/linkCard/…` route is for
            // pictures a fetch downloaded, and refuses these rows.
            'imageUrl' => $file?->thumbnailUrl(self::THUMBNAIL, self::THUMBNAIL, square: true),
            'imageWidth' => $file?->width,
            'imageHeight' => $file?->height,
            'fitSources' => $layout === CardLayout::Wide && $file !== null
                ? array_map(fn (int $box): array => ['url' => $file->thumbnailUrl($box, $box), 'box' => $box], self::FIT_BOXES)
                : [],
        ];
    }

    /**
     * The picture at each box, aspect kept.
     *
     * @return list<array{url: string, box: int}>
     */
    private static function fitSources(Model $record): array
    {
        $sources = [];

        foreach (self::FIT_BOXES as $box) {
            $url = CardContext::imageUrl($record, $box, $box);

            if ($url !== null) {
                $sources[] = ['url' => $url, 'box' => $box];
            }
        }

        return $sources;
    }

    /**
     * What the reader is told they are about to visit.
     *
     * `www.` is dropped because it distinguishes nothing a reader acts on, and the host is shown
     * rather than the site name so the label cannot be chosen by the page being previewed — a title
     * claiming one publisher over a link to another is the misdirection a card invites.
     */
    private static function domain(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return preg_replace('/^www\./i', '', $host) ?? $host;
    }
}

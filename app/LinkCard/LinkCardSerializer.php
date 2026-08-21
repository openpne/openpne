<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Models\LinkCard;
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
     * @return array{url: string, title: string, description: string|null, siteName: string|null, domain: string, layout: string, imageUrl: string|null, imageWidth: int|null, imageHeight: int|null, fitSources: list<array{url: string, box: int}>}|null
     */
    public static function card(Model $record): ?array
    {
        $kind = CardContext::forRecord($record);
        $card = $record->getAttribute('link_card_id') === null ? null : $record->getRelationValue('linkCard');

        if ($kind === null || ! $kind->carriesCard($record)) {
            return null;
        }

        if (! $card instanceof LinkCard || ! $card->isRenderable() || ! app(LinkCardSettings::class)->enabled()) {
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
            // the same reason the gates below are not restated per surface. See LinkCard::hasLargeImage.
            'layout' => $wide ? 'wide' : 'compact',
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

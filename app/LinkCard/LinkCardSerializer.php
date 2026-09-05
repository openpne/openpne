<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Models\LinkCard;
use App\Models\Member;
use App\Support\LinkCardStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * The one shape both surfaces read, so the gates (whether the setting is on, whether the card has
 * enough to draw, which URL its picture is served from) are decided once rather than per surface.
 * Nothing in the result is HTML: every field reaches a template as text to be escaped
 * (docs/internals/body-text.md).
 */
final class LinkCardSerializer
{
    /** The thumbnail edge, in CSS pixels — matched by the whitelisted image geometry. */
    private const THUMBNAIL = 120;

    /**
     * The same fit ladder a post's own images ship; each box is in `openpne.images.allowed_sizes`,
     * and a fit variant is at most the source's own size (docs/internals/images.md).
     */
    private const FIT_BOXES = [320, 640, 1200];

    /**
     * $viewer is passed rather than read off the request: an internal card is built from a record
     * the reader may not see, and no default is right for both a web-public page and a queued job.
     * No convenience fallback, so a call site that forgets it fails loudly instead of serving the
     * card to a guest.
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
            // Decided here so the two renderers cannot disagree, as the gates are (see CardLayout).
            'layout' => ($wide ? CardLayout::Wide : CardLayout::Compact)->value,
            // Never the file's own URL: a card is shared by every body mentioning the link, so the
            // address has to name this record for the request to be authorised against it.
            'imageUrl' => CardContext::imageUrl($record, self::THUMBNAIL, self::THUMBNAIL, square: true),
            // The size the bytes render at, from the File: the card row's own columns are what the
            // container declared before decoding, and a sideways-shot JPEG declares its sides the
            // other way round (`App\Files\ImageDimensions`).
            'imageWidth' => $card->image?->width,
            'imageHeight' => $card->image?->height,
            // Only the full-width shape asks for these; the thumbnail above is a fixed square.
            'fitSources' => $wide ? self::fitSources($record) : [],
        ];
    }

    /**
     * Nothing is read from the row but the URL and the pointer. The order is the design: URL
     * re-read and pointer must agree, unit before the record is loaded, then the record's own
     * access rule; every failure answers null so refusal and absence are indistinguishable
     * (docs/internals/link-cards.md, "Who may see one").
     *
     * @return array{url: string, title: string, description: string|null, siteName: string|null, domain: string, layout: string, imageUrl: string|null, imageWidth: int|null, imageHeight: int|null, fitSources: list<array{url: string, box: int}>}|null
     */
    private static function internalCard(LinkCard $card, ?Member $viewer): ?array
    {
        $link = InternalUrl::of($card->url);
        $target = $link->target;

        // A target is only set for our own host, so this one test covers both an address that has
        // stopped being ours and one of ours that names no record.
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

        $record = app(InternalCardResolver::class)->find($target, $link->recordId, $viewer);

        if ($record === null || ! $target->canView($record, $viewer)) {
            return null;
        }

        // The reader clicks the card's own URL, so a record that URL does not lead to — a talk
        // message reached through another room's path — is not described, however readable it is.
        if (! $target->urlLeadsTo($record, $link)) {
            return null;
        }

        $content = $target->content($record);

        if ($content === null) {
            return null;
        }

        $file = $content['image'];

        // As the fetched path does (CardContext::imageUrl): a File that is not an image gets no
        // URL, rather than one whose thumbnail route will 404 — a card is better bare than broken.
        if ($file !== null && $file->imageFormat() === null) {
            $file = null;
        }

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
            // against the record whose rule admitted this card; the `/linkCard/…` route is for
            // fetched pictures and refuses these rows.
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

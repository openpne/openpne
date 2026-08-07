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
     * $record's card, or null when there is nothing to draw.
     *
     * @return array{url: string, title: string, description: string|null, siteName: string|null, domain: string, imageUrl: string|null}|null
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

        return [
            'url' => $card->url,
            'title' => (string) $card->title,
            'description' => $card->description,
            'siteName' => $card->site_name,
            'domain' => self::domain($card->url),
            // Never the file's own URL: a card is shared by every body mentioning the link, so the
            // address has to name this record for the request to be authorised against it.
            'imageUrl' => CardContext::imageUrl($record, self::THUMBNAIL, self::THUMBNAIL, square: true),
        ];
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

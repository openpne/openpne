<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Files\ImageCache;
use App\Files\ImageTransform;
use App\LinkCard\CardContext;
use App\LinkCard\LinkCardImage;
use App\LinkCard\LinkCardSettings;
use App\Models\File;
use App\Models\LinkCard;
use Illuminate\Http\Response;

/**
 * Authorised through the post the URL names, re-deriving every condition from current data on each
 * request because each is something a URL can outlive (docs/internals/link-cards.md). Everything
 * fails as 404: a 403 would confirm that a card exists on a post the asker cannot see.
 */
class LinkCardImageController extends Controller
{
    public function show(
        string $context,
        int $record,
        string $format,
        string $geometry,
        string $name,
        string $ext,
        ImageCache $cache,
        LinkCardSettings $settings,
    ): Response {
        // The OpenPNE 3-shaped URL repeats the format in the directory and the extension.
        abort_unless($format === $ext, 404);
        abort_unless($settings->enabled(), 404);

        $kind = CardContext::fromSlug($context);
        abort_if($kind === null, 404);
        // Before the row is looked up: switching a module off has to stop its bytes, not only its screens.
        abort_unless($kind->feature()->enabled(), 404);

        $found = $kind->find($record);
        abort_if($found === null, 404);
        abort_unless($kind->canView($found, $this->viewerOrGuest()), 404);

        $card = $found->getRelation('linkCard');
        abort_unless($card instanceof LinkCard && $card->isRenderable(), 404);

        $file = File::query()->where('name', $name)->first();
        abort_unless($file !== null && $this->belongsToCard($file, $card), 404);

        $imageFormat = $file->imageFormat();
        abort_unless($imageFormat === $format, 404);

        $transform = ImageTransform::fromGeometry($geometry);
        abort_unless($transform !== null, 404);

        return response($cache->bytes($file, $transform, $imageFormat), 200, [
            'Content-Type' => $file->type,
            'X-Content-Type-Options' => 'nosniff',
            // `no-store` rather than a short private max-age: a cached copy outliving a post going
            // private is the failure this route exists to prevent.
            'Cache-Control' => 'private, no-store',
            // Not embeddable elsewhere: a cross-origin load would otherwise report, by succeeding or
            // failing, whether the requester can see a post they were never shown.
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }

    /**
     * Both directions are checked. Card-to-file rules out a URL that outlived a refresh; file-to-card
     * rules out serving any other stored image (an avatar) by pairing its name with a card the viewer
     * can see.
     */
    private function belongsToCard(File $file, LinkCard $card): bool
    {
        return $card->image_file_id === $file->id
            && $file->related_entity_type === LinkCardImage::RELATED_TYPE
            && (int) $file->related_entity_id === (int) $card->id;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Files\ImageCache;
use App\Files\ImageTransform;
use App\LinkCard\CardContext;
use App\LinkCard\LinkCardSettings;
use App\Models\File;
use App\Models\LinkCard;
use Illuminate\Http\Response;

/**
 * Serves a link card's picture, authorised through the post it appears under.
 *
 * A card is shared by URL, so its image can sit under a world-readable diary and a private one at
 * the same moment. Nothing about the File answers "may this person see it" — only the post they are
 * looking at does. So the URL names that post, and every request re-derives permission from it.
 *
 * **Every condition is checked again, on current data, on every request.** Not because any of them
 * is likely to have changed, but because each is something a URL can outlive:
 *
 *  1. the viewer may read the post — by that post kind's own rule, never a copy of it;
 *  2. the post still points at this card — it may have been edited to a different link;
 *  3. the card still points at this file — a refresh may have replaced the picture;
 *  4. the file still belongs to that card — the id in the URL is not evidence of anything;
 *  5. the feature is on and the card is renderable — an operator can switch it off.
 *
 * A signed URL would not replace any of this: signing proves the link was issued, not that what it
 * described is still true, and a link issued before a post went private stays valid for its lifetime.
 *
 * Everything fails as 404, matching the rest of the app: a 403 would confirm that a card exists on a
 * post the asker cannot see.
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
            // The answer depends on who asked, so it must not be reused for anyone else. `no-store`
            // rather than a short private max-age: the case that matters is a post going private,
            // and a cached copy that outlives the change is the whole failure being avoided.
            'Cache-Control' => 'private, no-store',
            // Not embeddable elsewhere: a cross-origin load would otherwise report, by succeeding or
            // failing, whether the requester can see a post they were never shown.
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }

    /**
     * Whether $file really is $card's current picture.
     *
     * Both directions are checked. The card naming the file rules out a URL that outlived a refresh;
     * the file naming the card rules out pointing this endpoint at any other stored image — an
     * avatar, someone's diary photo — by pairing its name with a card the viewer happens to be able
     * to see.
     */
    private function belongsToCard(File $file, LinkCard $card): bool
    {
        return $card->image_file_id === $file->id
            && $file->related_entity_type === 'link_card'
            && (int) $file->related_entity_id === (int) $card->id;
    }
}

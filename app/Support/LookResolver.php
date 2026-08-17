<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SnsSettingService;
use Illuminate\Http\Request;

/**
 * Decides which App\Support\Look a request renders in. URLs carry no look — it is an attribute of
 * the viewer — so every consumer asks here and the shell ships the answer as one shared prop.
 *
 * The chain is guest clamp → site default. A session preview and a durable member choice are
 * planned between the two, in that order: the preview is what a member is trying on right now, the
 * preference what they settled on, and the site default what an undecided member gets
 * (docs/internals/looks.md).
 */
final class LookResolver
{
    public static function resolve(Request $request): Look
    {
        // A look is a member's way around their own pages, and a signed-out visitor reaches none of
        // them — so the site default does not answer for a guest.
        if ($request->user('member') === null) {
            return Look::Standard;
        }

        return app(SnsSettingService::class)->get(SnsSettingKey::DefaultLook);
    }
}

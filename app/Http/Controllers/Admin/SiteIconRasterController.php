<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Files\GeneratedIcon;
use App\Files\GeneratedIconRaster;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * Gated by the `admin` guard alone, checked in the action so a non-admin gets a flat 404; an
 * administrator can already upload any favicon, so this grants nothing new.
 */
class SiteIconRasterController extends Controller
{
    public function store(Request $request, GeneratedIcon $icon, GeneratedIconRaster $raster): Response
    {
        abort_unless(Auth::guard('admin')->check(), 404);

        // A stale draw: the name or the color changed while the browser was drawing the old ones.
        abort_unless($request->input('version') === $icon->version(), 409);

        $pngs = [];
        foreach (GeneratedIconRaster::SIZES as $size) {
            $file = $request->file("icon.{$size}");
            $pngs[$size] = $file instanceof UploadedFile && $file->isValid() ? (string) $file->get() : '';
        }

        try {
            $raster->store($icon->version(), $pngs);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response()->noContent();
    }
}

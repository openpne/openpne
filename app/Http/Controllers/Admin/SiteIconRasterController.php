<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Files\GeneratedIcon;
use App\Files\GeneratedIconRaster;
use App\Http\Controllers\Controller;
use App\Services\SnsSettingService;
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
    public function store(Request $request, GeneratedIcon $icon, GeneratedIconRaster $raster, SnsSettingService $settings): Response
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

        // The version is read again off the rows, past this request's memo: a rename while this draw
        // was in flight must keep its own, newer raster.
        $settings->clearCache();
        $raster->prune($icon->version());

        return response()->noContent();
    }
}

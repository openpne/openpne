<?php

namespace App\Compat\Parities;

use App\Compat\CompatLevel as L;
use App\Compat\RouteMap;
use App\Compat\RouteParity;
use App\Compat\ScreenElement;
use App\Compat\ScreenStatus as S;

/** opUploadFilePlugin's `f` module: only its download URL is kept, for the links in migrated bodies. */
class UploadFileRouteParity extends RouteParity
{
    protected string $module = 'f';

    public function maps(): array
    {
        return [
            new RouteMap('file_show', '/f/show/:filename', 'file.legacy_upload', 'GET', op3Action: 'show'),
        ];
    }

    /** The plugin declares no `f_nodefaults` route, so the global /:module/:action fallback stays on. */
    public function acknowledgesGlobalFallback(): bool
    {
        return true;
    }

    public function screens(): array
    {
        return [
            'show' => [
                new ScreenElement('download of a file linked from a body', L::One, S::Ported, 'f/actions.class.php show + opToolkit::fileDownload', 'found by id with the extension ignored, as there; FilePolicy decides who may read it where OpenPNE 3 asked only for a login'),
                new ScreenElement('header upload box with URL to paste', L::Two, S::Missing, 'fMenu side-banner gadget, _fMenu.php', 'a file is attached to the post instead'),
            ],
        ];
    }
}

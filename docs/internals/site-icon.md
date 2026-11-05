# Site icon

The icon a site shows in the browser tab, on the home screen and on an installed app. Nothing is
shipped in `public/`: every icon is either an upload or made from the brand color and the site name.

## Three states

[`SiteIcon`](../../app/Files/SiteIcon.php) resolves them from two settings, `brand_favicon_file`
(a `files.name` token) and `brand_favicon_fallback` (`generated` by default, or `none`), both set
on the [`BrandingSettings`](../../app/Filament/Pages/BrandingSettings.php) page. Both shells render
the same partial (`resources/views/partials/site-icons.blade.php`), and the admin panel follows.

| State | When | Tab (`rel="icon"`) | `/favicon.ico` | Home screen, manifest, push |
|---|---|---|---|---|
| uploaded | a token is set | the upload | redirect to the upload | derived from the upload ([`AppIcon`](../../app/Files/AppIcon.php)) |
| generated | no token, fallback `generated` | `/favicon.ico` (32×32) then `/favicon.svg` | the generated ICO | the generated icon (`/site-icon/{size}.png`) |
| none | no token, fallback `none` | no link | a bare 404, cacheable | the generated icon |

`none` reaches only the tab icon: it keeps what OpenPNE 3 showed, which had no manifest or push
icon to keep. The upgrade stamps it for a migrated site ([upgrade.md](upgrade.md), "Post-walk
passes"); a fresh install starts on `generated`. A `public/favicon.ico` left over from an earlier
release shadows the route under `try_files`, so a deploy that overlays a release must remove it, and a
web-server rule that answers `/favicon.ico` or image extensions itself hides the routes the same way.

## The generated icon

[`GeneratedIcon`](../../app/Files/GeneratedIcon.php) draws the SVG from the brand color (the
built-in one when unset) and the initial of the site name, by the same rule as the Modern brand mark
([`Initial`](../../app/Support/Initial.php) is the server twin of `computeInitial` in
`resources/js/lib/identity-mark.ts`). A raster letter needs a font, and none is shipped, so the raster
forms — the ICO and the home-screen PNGs — are drawn by an administrator's browser: a body-end hook on
every admin page (`resources/views/filament/site-icon-raster.blade.php`) draws the SVG onto a canvas
in each size with the fonts of that administrator's machine and posts the PNGs once per browser
session to `/admin/site-icon/raster`, which [`GeneratedIconRaster`](../../app/Files/GeneratedIconRaster.php)
keeps on the image cache disk under the icon's version (the 32 px one keeps the rounded corners, the
larger ones are square for the OS to mask). Until a raster for the current color and name exists — a
fresh install before its first admin visit, a renamed site until the next — the routes answer with a
square of the brand color and no letter, built byte by byte rather than through GD (optional on an
imgproxy install), and cached for minutes rather than a day. The `none` state draws too: its home
screen shows the generated icon.

Every generated URL carries `v`, a hash of the color and the name with `-r` appended once the raster
exists, because an installed app re-fetches its icon off a changed URL, not off new bytes at an
unchanged one. The one exception is
an upload that cannot fill a size (too small, refused, or the processor down), which answers with
the tile under the upload's own URL for the day it is cached; a changed brand color repaints it in
place.

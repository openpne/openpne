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
| generated | no token, fallback `generated` | `/favicon.ico` (32×32) then `/favicon.svg` | the generated ICO | the brand-color tile (`/site-icon/{size}.png`) |
| none | no token, fallback `none` | no link | a bare 404, cacheable | the brand-color tile |

`none` reaches only the tab icon: it keeps what OpenPNE 3 showed, which had no manifest or push
icon to keep. The upgrade stamps it for a migrated site ([upgrade.md](upgrade.md), "After the
walk"); a fresh install starts on `generated`.

## The generated icon

[`GeneratedIcon`](../../app/Files/GeneratedIcon.php) draws the SVG from the brand color (the
built-in one when unset) and the initial of the site name, by the same rule as the Modern brand mark
([`Initial`](../../app/Support/Initial.php) is the server twin of `computeInitial` in
`resources/js/lib/identity-mark.ts`). The raster forms — the ICO and the PNG tile — are a square
of the brand color with no letter: a raster letter needs a font, and none is shipped. They are built
byte by byte rather than through GD, which an imgproxy install may not have.

Every generated URL carries `v`, a hash of the color and the name, because an installed app
re-fetches its icon off a changed URL, not off new bytes at an unchanged one. The one exception is
an upload too small for a size, which answers with the tile under the upload's own URL; a changed
brand color repaints it in place.

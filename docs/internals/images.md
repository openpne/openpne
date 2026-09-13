# Images

An uploaded picture is stored once and drawn from generated variants, served at OpenPNE 3's
`/cache/img/{format}/{geometry}/{name}.{ext}` address. Generation and caching are
[`ImageCache`](../../app/Files/ImageCache.php); the geometry grammar and the size whitelist are
[`ImageTransform`](../../app/Files/ImageTransform.php) and `config/openpne.php`. This document
covers the part a caller decides: **which variant to ask for**, and the intrinsic size that travels
beside it.

## A variant is a source, not a box

Where CSS decides the size (every Modern surface), a variant is a candidate in a `srcset` and the
browser picks from it. Attachment serializers therefore ship **ladders**, not a pair keyed to one
placement: the same record is drawn in a 300px post cell and a 192px boxed cell, and a candidate
list that names densities for one of those is wrong for the other. Naming a 1200px source "2x"
only holds if the box is 600 CSS px wide.

So the descriptors are `w` — each candidate states its real intrinsic width, and the browser
combines that with the `sizes` the placement declares. Density follows from the two, per placement,
instead of being asserted by the server.

## The two ladders

| | shape | descriptor | for |
|---|---|---|---|
| `fitSources` | scales inside a square box, keeps the aspect ratio, never upscales | 320 / 640 / 1200 box | a picture shown at its own shape |
| `cropSources` | centre-crops to fill the cell ratio exactly, upscaling a smaller source | 300 / 600 wide, per ratio | a fixed-shape grid cell |

`cropSources` is keyed by cell ratio — `tall` is 3:4 (the two-image cells and the three-image left
cell), `wide` is 3:2 (the three-image right cells, and every cell of a set past three, which only a
migrated post has). The crop happens **once, on the server, at the
ratio the cell actually is**. Cropping to a square and letting CSS `object-fit: cover` finish the
job is not the same picture: cover scales until the shorter side fills, so a square source in a 3:2
cell is zoomed 1.33x past what a 3:2 crop would show and loses the top and bottom of the frame.
Extreme aspect ratios lose more. CSS `cover` is still applied on the cell, but with the source
already at the cell's ratio it only scales — it does not re-crop.

A crop candidate's intrinsic width is exactly the number in the URL, so its `w` descriptor is
literal. A fit candidate's is not: `w640_h640` of a landscape photo is 640 wide, of a portrait it is
narrower, and of a source smaller than the box it is the source's own width. The client derives it
from `width`/`height`, which travel in the same entry. **With no recorded size there is nothing to
derive**, so a surface then drops the `srcset` and paints the middle candidate (640) alone.

The ladders are shared across every placement, which is what makes them safe to serve without
knowing the placement — and what leaves a floor: the smallest candidate is larger than the smallest
box any surface paints, so a 192px boxed cell at 1x still fetches a 300px-class crop. That is a
bounded overshoot of tens of kilobytes, taken deliberately: closing it means per-placement
candidates, and every added size multiplies cached variants across the whole file corpus.

`thumbnailUrl` — the 120px square — stays on those entries for the surfaces that read it.

## Adding a size

`allowed_sizes` is a whitelist of `WxH` targets, and an unlisted one is a 404, so a request cannot
drive unbounded generation. Each entry opens both the fit and the `_sq` crop, in every stored format,
under the current cache generation. Add a size a surface actually paints, not a size that might be
wanted. An entry listed in `animated_sizes` as well opens a third form, the `_a` animation, which
costs about a canonical per picture — a 15 MB GIF makes a 15 MB `_a` at every size that offers it —
so that list stays the fit rungs a surface will actually animate.

## files.width / files.height

`FileUploader` records the pixel size of a raster upload from its **canonical**, the full-size
re-encode [`ImageCache`](../../app/Files/ImageCache.php) keeps at the `w_h` key and draws every
variant from. Both columns are nullable, and **null means unknown** — a non-raster file, a row written
before the columns existed, or OpenPNE 3 data (which records no dimensions). A zero side counts as
unknown too, since consumers divide by it.

The recorded size is therefore the **rendered** size, not the container's declared one: the processor
applies EXIF Orientation as it re-encodes, so a photo shot sideways declares 4032x3024 and is recorded,
like it is drawn, as 3024x4032. Under the GD processor that needs `ext-exif` (a Composer `suggest`);
without it neither the canonical nor the size is turned upright, consistently.

Consumers must handle null rather than substituting a guess: a reserved box of the wrong shape moves
the layout twice, once when it is reserved and again when the picture disagrees with it.

`files.animated` is recorded beside the size, from the same canonical: true when the processor kept
more than one frame, false when it kept one, null when nothing has recorded it yet or the processor
could not tell ([`AnimationProbe`](../../app/Files/AnimationProbe.php) reads the answer's container;
a walk it cannot finish is null, never a guess, and a GIF over `AnimationProbe::MAX_GIF_WALK_BYTES` is
not walked at all, the walk costing about three times its bytes in memory). Only a canonical is
probed; a variant's frames are nobody's fact. GD records false; a true recorded before a switch to
GD stands until the next `warm` or `rebuild`, and until then the `_a` variant under GD's own key is a
still. The fact is what lets a fit variant be asked for animated: `w640_h640_a` is answered only for a file whose `animated` is true,
and 404 otherwise, unknown included — a still served under the `_a` key would keep its ETag after
the fact was recorded and stay a still in every browser that saw it. `warm` fills a null from the
canonical on the disk, and `rebuild` rewrites the fact from the new canonical, since another processor
may keep frames this one did not.

A raster upload the processor refuses is refused as an upload — the canonical is produced before the
row is saved — so a raster File created by an upload always has a size. Non-raster files are stored
without one.

`openpne:image-cache warm` makes the canonical of every picture that has none — a row imported from
OpenPNE 3, or one evicted from the cache disk — and records the size of every row missing one. Run it
after an OpenPNE 3 upgrade; it is idempotent and an interrupted run resumes by being re-run. A picture
the processor refuses is remembered as refused ([security](security.md), "Decoding an upload"):
`status` lists those with the reason, and `warm --retry-failed` asks the processor again (after a
limit was raised, say). `rebuild` discards every derived file of every picture — older encoder
directories included, which nothing else reclaims — and makes the canonicals afresh, recording the
size again; it is for bytes that changed under an unchanged key (a GD or libvips update, a damaged
cache disk), a quality or processor change already being a new key. `rebuild` discards a picture's
files only once its new canonical is in hand, and either run exits non-zero when any picture could
not be made — the processor unavailable, the stored bytes unreadable, the cache disk refusing the
write — so a deploy script notices. Three refused writes in a row end the run, since `rebuild` has
discarded before it writes and a full disk would otherwise empty the cache; a row whose bytes are
gone keeps the exit non-zero until the row is deleted, there being nothing to show for it anyway. A
row stored under an image type this version does not show as a picture (`image/pjpeg`, say, which
OpenPNE 3 accepted) is counted as unshown and listed.

## Upload size

Every image upload — a member's avatar or post images, an admin's banner, logo or public asset, and
a picture posted over MCP — is held to one per-file cap,
[`UploadLimit`](../../app/Files/UploadLimit.php): `OPENPNE_IMAGE_MAX_UPLOAD_KB`, 5120 by default,
the favicon alone keeping its own 1 MB ceiling under it. The admin forms
upload through Livewire's temporary endpoint before Filament validates, so
`FilesServiceProvider` sets that endpoint's rule to the same cap; unconfigured, Livewire's own
12288 KB would silently be the admin cap above that size. That rule is global to Livewire uploads,
which is fine while every one of them is an image; a non-image admin upload would need its own.

A stored image has a second pair of caps, [`ImageSourceLimit`](../../app/Files/ImageSourceLimit.php):
`OPENPNE_IMAGE_MAX_SOURCE_KB` and `OPENPNE_IMAGE_MAX_SOURCE_PIXELS` bound what the image processor
will read and decode, whatever the upload rules were when the bytes arrived — a row imported from
OpenPNE 3 met none of them ([security](security.md), "Decoding an upload"). Blank or non-positive,
each follows the upload rules (at least 20480 KB or the upload cap, and the per-side limit squared),
never no cap; a set value is taken as given. A favicon the processor refuses is remembered as such
until `openpne:image-cache rebuild` asks again (`warm --retry-failed` does too, when the picture itself
was refused), or the favicon is uploaded again, whatever the caps are set to in between.

The upload cap is read as configured, a blank or non-positive value meaning the shipped default; PHP's ini
limits are not folded in, because they belong to the deployment and differ between the FPM pool
that serves uploads and the CLI that runs tests and commands. Those limits, and the reverse proxy's,
are prerequisites the operator sets alongside the cap, and the shipped `docker/` stack sizes them
for the default only. `upload_max_filesize` must be at least the cap: above it PHP discards the
file before validation runs, and the form reports a failed upload rather than the size.
`post_max_size` bounds the whole request, and a compose form carries up to `PostImages::MAX_IMAGES`
files plus its fields, so it must hold the cap times that count with room for the rest of the body;
over it the answer is a bare 413 from `ValidatePostSize`, the composed post lost with it. The proxy's
body limit (nginx `client_max_body_size`, 1 MB unconfigured) sits in front of both and fails the same
way, out of the app's sight.

The MCP wire carries pictures as base64 inside a JSON body, 4/3 the bytes again; it never touches
`$_FILES`, and the decoded file is then run through the compose forms' own rules, so the same cap
is applied to the encoded length before the decode. That pre-decode bound scales with the cap: the
base64 of up to `MAX_IMAGES` pictures at the cap is in memory before any of it is refused.

The upgrade does not copy OpenPNE 3's `image_max_filesize`; a run, dry or real, prints the value to
set, or says the OpenPNE 3 value could not be read as a size.

## Processing

Every decode goes through [`ImageProcessor`](../../app/Files/ImageProcessor.php), chosen by
`OPENPNE_IMAGE_PROCESSOR`. `gd`, the default, decodes in the PHP process and needs nothing beyond
`ext-gd`; `imgproxy` hands the bytes to an [imgproxy](https://imgproxy.net) sidecar the operator runs
([`ImgproxyImageProcessor`](../../app/Files/Imgproxy/ImgproxyImageProcessor.php)). Both are held
to one contract test: no source metadata survives a re-encode, EXIF Orientation is applied, a variant
is a still unless its URL asks for the frames with `_a`, and the same header check refuses the same
sources before anything is decoded. What differs:

| | `gd` | `imgproxy` |
|---|---|---|
| Colour | the ICC profile is dropped, so a wide-gamut photo shifts | converted to sRGB |
| Animation | the canonical and every variant are stills | a GIF or animated WebP canonical keeps up to `ImgproxyImageProcessor::MAX_FRAMES` frames within the sidecar's 50 MP in total, over that a still, and a fit variant asked for with `_a` does the same (a crop never); an APNG is a still under both, libvips reading its first frame like libpng |
| Where the decode runs | the php-fpm worker | the sidecar |
| To install | nothing | the container (the compose file runs one) and three env values |

Switching changes the encoder directory of every cache key, so each picture is made afresh on its next
view or by `openpne:image-cache warm`, which also records anew whether it animates; `rebuild` reclaims
the old directories.

**Transport.** The app writes the bytes to the `image_spool` disk (`storage/app/image-spool`,
world-readable because the sidecar runs as another user), asks the sidecar for
`local:///<prefix><name>` over a URL signed with `OPENPNE_IMGPROXY_KEY` / `OPENPNE_IMGPROXY_SALT`
(the sidecar's own `IMGPROXY_KEY` / `IMGPROXY_SALT`), reads the answer into a sink capped at the source cap, and
deletes the spooled file; leftovers of a request that died are swept an hour later on the next
write. No route of this app serves stored bytes to the sidecar, so it needs no path back to the app.
`OPENPNE_IMGPROXY_SOURCE_PREFIX` is the spool directory's path under the sidecar's
`IMGPROXY_LOCAL_FILESYSTEM_ROOT`, blank when that root is the spool itself as in the compose file.
A sidecar of the operator's own needs three settings besides the key and salt:
`IMGPROXY_LOCAL_FILESYSTEM_ROOT`, `IMGPROXY_ALLOWED_SOURCES=local://` and
`IMGPROXY_ALLOW_SECURITY_OPTIONS=true` — the last because every request states its own frame budget
with `max_animation_frames` (one for a variant, `MAX_FRAMES` for a canonical), so the sidecar's default
of a single frame does not apply, and it is safe because only the holder of the key can sign a request.
Every request also asks for metadata, colour-profile and copyright stripping and auto-rotation, so the
sidecar's defaults for those do not matter either, and the app dials nothing but this one address
([outbound-http](outbound-http.md), "Key invariants").

**What an answer means**, measured on imgproxy v4.0.14 (its documentation specifies only the 429):

| imgproxy answers | when | the app |
|---|---|---|
| 200 | processed | keeps the result |
| 422 `Invalid source image` | not an image; over `IMGPROXY_MAX_SRC_RESOLUTION` (50 MP unconfigured), counted over every frame kept of an animation; over `IMGPROXY_MAX_SRC_FILE_SIZE` where an operator set one (the shipped stack leaves it off, the app's own cap having applied first) | refuses the picture, remembered as a refusal — a GIF or WebP canonical is first asked for again as a still |
| 500 `Internal error` | libvips could not load the bytes (a PNG with no pixel data), or could not this once | an outage: `/health` cannot tell the two apart, so nothing is remembered and the next view asks again |
| 200 running past the source limit | a re-encode that outgrows the cap | the transfer is cut; a canonical is refused, as the limit would refuse it anyway, and a variant, drawn from a canonical within the limit, is an outage |
| 429, 503, other 5xx; no connection; timeout; a 200 whose bytes are not the format asked for | overloaded, down, or a proxy in front of it | an outage: 503 to the viewer, nothing remembered, an error logged |
| 403, 404, other 4xx | wrong key or salt, the spool not visible, an option this imgproxy does not know | an outage, logged: the operator's to fix |

libvips is more tolerant than GD: a truncated JPEG, a PNG with a bad CRC or with garbage pixel data
decodes to *something* rather than being refused, so what GD refuses and imgproxy accepts differs at
the edges; the contract test pins only what both refuse. An outage on the upload path is shown to the
member as a temporary failure; on a read it is a 503 with `Retry-After`, and every canonical already
made keeps being served. A canonical that keeps its frames can outgrow the source cap where a GD still
would not, and is then refused like any other over it.

## Classic is not part of this

A Classic `<img>` carries no width or height, so the variant it requests *is* the rendered size and
changing it moves the layout. Classic keeps its 120px square.

## Key invariants

- A variant request is a source for a `srcset`, never the painted box — except on Classic.
- `w` descriptors only, and only ever a candidate's true intrinsic width. A fit ladder without
  `files.width`/`height` to derive from ships no `srcset` at all.
- A cell is cropped server-side at its own ratio; CSS `cover` may scale that source but never
  re-crops it to a different shape.
- Every size a surface asks for is in `allowed_sizes`, at every rung of the ladder.
- `files.width` / `files.height` are nullable and null means unknown. Nothing may treat a missing
  size as an error, and nothing may invent one.
- A recorded size is the size the picture renders at, EXIF Orientation applied.
- A fit variant is at most the source's own size; a crop variant is always exactly its box, source
  permitting or not.
- `_a` is the animated form of a fit box in `animated_sizes` (`w640_h640_a`), answered only for a
  file whose recorded `animated` is true and 404 otherwise; a crop has no animated form, and neither has `w_h` (the
  canonical keeps its frames where the processor does). An animated variant the sidecar refused over
  budget is cached as a still until `rebuild`.
- A variant's cache key carries token, geometry (`_sq` / `_a` included), format, generation, and the
  encoder — the `processor`, `quality`, and whether `ext-exif` is present — so any of those changing
  is a new variant, not a stale one. The canonical is the `w_h` key under the same encoder directory, and a
  refused file leaves a `w_h.failed` marker there instead; every variant is drawn from the canonical,
  never from the stored bytes. It does **not** carry library or host versions (intervention/image, GD,
  their codecs): a change there has to bump `GENERATION`. Adding a segment to the key is itself
  such a change: every variant regenerates on its next request, and the superseded files stay on
  the cache disk until their File is deleted (nothing prunes them).
- **Clearing the cache disk no longer reaches browsers**: every inline raster answer — `/cache/img/…`
  variants, the `w_h` original, `/file/{name}`, the banner and public routes — carries the canonical's
  or the variant's key hashed as its `ETag` (`ImageTransform::etag`). A non-raster attachment and the
  admin raw route carry the file token, whose bytes never change. Each is checked after the route's own gate
  (`FilePolicy`, or the admin guard on the raw route) and before any bytes are read, and `max-age`
  is not shortened for it — revalidating every image would cost a PHP request each. Only the
  link-card image route carries no validator (it is `no-store` by design).

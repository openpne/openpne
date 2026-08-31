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
wanted.

## files.width / files.height

`FileUploader` records the pixel size of an upload whose type is `image/*`, from the bytes it is
already holding. Both columns are nullable, and **null means unknown** — a non-image, a row written
before the columns existed, OpenPNE 3 data (which records no dimensions), or bytes that do not
decode. A zero side counts as unknown too: a header-only decode reports one, and consumers divide by
it.

The recorded size is the **rendered** size, not the container's declared one.
[`ImageDimensions`](../../app/Files/ImageDimensions.php) reads EXIF Orientation and swaps the sides
for the quarter-turn values (5-8), because delivery decodes through intervention/image, which
auto-orients before it scales: a photo shot sideways declares 4032x3024 and draws 3024x4032. This
holds on both ingestion paths — the metadata stripper drops EXIF but re-emits Orientation in a
minimal APP1 precisely so rotation survives, so stripped bytes still declare the unrotated size.

Reading Orientation needs `ext-exif` (a Composer `suggest`, and already required for thumbnails of
rotated photos to render upright at all). Without it the swap does not happen and the declared size
is recorded — the same reading intervention/image makes when it cannot auto-orient either.

Consumers must handle null rather than substituting a guess: a reserved box of the wrong shape moves
the layout twice, once when it is reserved and again when the picture disagrees with it.

Reading a size never fails an upload. It is metadata for layout, not a validation gate — the upload
rules (type, byte size, `max_upload_dimension`) are elsewhere and unaffected.

`openpne:backfill-image-dimensions` fills the rows that have none, reading each file's bytes through
the `FileStorage` seam. Run it after an OpenPNE 3 upgrade. It selects only null rows, so it is
idempotent and an interrupted run resumes by being re-run; a file whose bytes are gone or undecodable
is left null and reported as skipped rather than stopping the run.

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
- A variant's cache key names everything its bytes depend on — token, geometry, format,
  generation, and the encoder (`driver`, `quality`) — so an env change is a new variant, not a
  stale one. Adding a segment to the key is itself such a change: every variant regenerates on its
  next request, and the superseded files stay on the cache disk until their File is deleted (nothing
  prunes them). **Clearing the cache disk no longer reaches browsers**: `/cache/img/…` answers carry
  that key hashed as their `ETag` (`ImageTransform::etag`), so a code change that alters the bytes
  has to bump `GENERATION`. `/file/{name}` and the admin raw route carry the file token, whose
  bytes never change. Each is checked after the policy and before any bytes are read, and
  `max-age` is not shortened for it — revalidating every image would cost a PHP request each.
  The public asset, banner and link-card image routes carry no validator (the last is `no-store`
  by design).

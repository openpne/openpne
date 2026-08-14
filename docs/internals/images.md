# Images

An uploaded picture is stored once and drawn from generated variants, served at OpenPNE 3's
`/cache/img/{format}/{geometry}/{name}.{ext}` address. Generation and caching are
[`ImageCache`](../../app/Files/ImageCache.php); the geometry grammar and the size whitelist are
[`ImageTransform`](../../app/Files/ImageTransform.php) and `config/openpne.php`. This document
covers the part a caller decides: **which variant to ask for**, and the intrinsic size that travels
beside it.

## Ask for twice the box

Where CSS decides the size (every Modern surface), the variant is a *source*, not the painted box.
A screen at DPR 2 draws twice the CSS pixels, so a source the size of the box is upscaled on most
phones. Surfaces therefore ship a pair — the box size and its double — as a `srcset` with **`1x` /
`2x` descriptors**.

`w` descriptors are not used. A `w` candidate states its real intrinsic width, and a fit variant's
intrinsic width depends on the source's aspect ratio: `w600_h600` of a landscape photo is 600 wide,
of a portrait it is narrower. Producing a correct `w` list would mean computing every candidate per
image from `files.width`/`height` — and being unable to produce one at all wherever that is null. An
`x` descriptor names the density it suits, which is true regardless of the picture.

A fit pair is not always a true 1x/2x: a fit variant never upscales, so for a source between the two
sizes the 2x candidate comes back at the original size, short of double. That is the best the source
has, and omitting the candidate leaves the same screen upscaling the 1x one instead. A square pair
has no such gap and pays for it the other way — a crop fills its box exactly, so a small source is
upscaled into a large, soft file that is the stated density in name only.

## Fit or square

Both forms come from the same whitelist entry, and the choice follows what the layout does with the
result:

| | | for |
|---|---|---|
| fit (`thumbnailUrl(600, 600)`) | scales inside the box, keeps the aspect ratio, never upscales | a picture shown at its own shape |
| square (`thumbnailUrl(600, 600, square: true)`) | centre-crops to fill the box exactly, upscaling a smaller source | a fixed-shape cell drawn with `object-fit: cover` |

A cover cell fed a fit variant downloads pixels it then crops away, and a picture shown at its own
shape fed a square variant is a picture with its edges cut off. Every attachment serializer ships
both pairs (`fitUrl` / `fit2xUrl`, `squareUrl` / `square2xUrl`) so a surface picks per placement
rather than per record.

`thumbnailUrl` — the 120px square — stays on those entries for the surfaces that read it.

## Adding a size

`allowed_sizes` is a whitelist of `WxH` targets, and an unlisted one is a 404, so a request cannot
drive unbounded generation. Each entry opens both the fit and the `_sq` crop, in every stored format,
under the current cache generation: an added size multiplies cached variants across the whole file
corpus. Add the partner of a size a surface actually paints, not a size that might be wanted.

## files.width / files.height

`FileUploader` records the pixel size of an upload whose type is `image/*`, from the bytes it is
already holding. Both columns are nullable, and **null means unknown** — a non-image, a row written
before the columns existed, OpenPNE 3 data (which records no dimensions), or bytes that do not
decode. A zero side counts as unknown too: a header-only decode reports one, and consumers divide by
it.

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
- Density (`x`) descriptors only. A `w` descriptor would have to be derived per image, and cannot be
  derived at all for a file with no recorded size.
- Cover cells take the square variant; anything drawn at its own aspect ratio takes the fit variant.
- Every size a surface asks for is in `allowed_sizes`, in both the size and its double.
- `files.width` / `files.height` are nullable and null means unknown. Nothing may treat a missing
  size as an error, and nothing may invent one.
- A fit variant is at most the source's own size; a square variant is always exactly its box, source
  permitting or not.

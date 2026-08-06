# Link cards

A URL pasted into a body renders as a card — the linked page's title, description, image and site —
so a reader can tell what a link is without opening it. The metadata comes from the standards
publishers already maintain: **Open Graph** first, then **Twitter Cards**, then plain HTML, with
**oEmbed** filling in only what those left out.

Open Graph before oEmbed is a deliberate divergence from Mastodon, which prefers oEmbed. Reading
Open Graph costs nothing beyond the response already in hand, so the common case is one request per
link instead of two.

This document covers acquisition and storage. The outbound safety rules every fetch here obeys are
in [outbound-http.md](outbound-http.md).

## The card is never HTML

Everything a card is drawn from is text plus a locally-stored image, and it is rendered as
structured data next to the body rather than inside it. That is not a stylistic choice: the body
pipeline's invariants are that trusted HTML comes only from `BodyRenderer`, that `bodyHtml` is never
assembled from anything a client supplied, and that the sanitizer allowlist has no `img` or `iframe`
([body-text.md](body-text.md)). Putting a card into the body would break all three.

So **oEmbed's `html` field is never read** — it is provider-authored markup, usually an iframe, and
there is nothing it could contribute that text and an image do not already give. Mastodon refuses
oEmbed's `rich` type for the same reason. `OembedClientTest` pins that no field of the extracted
metadata ever carries markup.

## Pieces

| | |
|---|---|
| [`LinkUrl`](../../app/LinkCard/LinkUrl.php) | Normalises a URL into the key a card is stored under; resolves references found in a page |
| [`Encoding`](../../app/LinkCard/Encoding.php) | Converts a fetched page to UTF-8 |
| [`MetadataExtractor`](../../app/LinkCard/MetadataExtractor.php) | Markup in, `LinkMetadata` out. Pure — it never fetches |
| [`OembedClient`](../../app/LinkCard/OembedClient.php) | Calls a discovered oEmbed endpoint for the structured fields only |
| [`LinkCardImage`](../../app/LinkCard/LinkCardImage.php) | Downloads the card image and stores it as a local `File` |
| [`LinkCard`](../../app/Models/LinkCard.php) | The cached row, one per normalised URL |

## What normalisation may and may not do

Two spellings of one page should share a card; two different pages must never collide. That
asymmetry decides everything:

- **Normalised**, because none of it changes which resource is addressed: scheme and host case,
  default port, trailing dot, IDN → Punycode, and the fragment (which never reaches the server).
- **Kept in full: the query.** Plenty of sites put the article id there, so stripping or reordering
  it would merge unrelated pages into one card. Tracking parameters therefore split the cache, which
  is the safe direction to be wrong in.
- `http` and `https` stay distinct — different origins, and the fetcher treats them as such.

## Encoding is not an edge case

Shift_JIS and EUC-JP pages are still numerous, and the parser assumes UTF-8. Getting this wrong does
not fail loudly; it stores a title of mojibake.

The declared charset is not taken at its word, and the obvious check does not work: almost any UTF-8
Japanese text is *also* a valid CP932 byte sequence, so "does the declared charset fit?" answers yes
for a page mislabelled Shift_JIS by a CMS template. **Valid UTF-8 is the discriminating test and runs
first** — its structure is strict, while Shift_JIS lead bytes are not valid UTF-8 in any position, so
a genuine legacy page fails it immediately and falls through to its declaration.

## The image is copied, and the order of checks is the safety

Card images are downloaded and stored as local `File` rows rather than hot-linked, so a reader's
browser never contacts the linked site, the card survives the far end moving or blocking referrers,
and the existing thumbnail pipeline serves it. They are `explicit_visibility = public`: the source is
a public web image and the card appears on pages a logged-out visitor may see.

```
byte cap → finfo (real media type) → header dimensions → size check → decode → store
```

**The size check must precede the decode.** A decoder allocates width × height × 4 bytes before
anything can inspect the result, so a 40000×40000 PNG that is a few kilobytes on the wire exhausts
memory during a validation step that runs too late. `LinkCardImageTest` asserts the decoder is called
zero times for an oversized header — and that it *is* called for an acceptable one, so the guarantee
cannot be satisfied by never decoding at all.

Content-Type is the far end's claim, so the real type comes from `finfo`. SVG is refused: it is a
scriptable document, and this one would be served from our own origin.

Metadata stripping happens exactly once, inside `FileUploader`; the bytes are handed over as an
`UploadedFile` so there is a single strip in the pipeline rather than one here and another there.

## Testing against the real guard

`FakesOutboundTransport` substitutes the socket and the DNS resolver **underneath** a real
`SafeHttpFetcher`, never the fetcher itself. A stubbed fetcher would let these tests pass while the
destination check, the connection pin, the byte cap and the media-type rules never ran, so any of
them could regress without a red test. The fake also reports the pinned address as the peer by
default — a fake reporting none would send every test down the unverified path, which is the shape of
the hole the suite exists to catch.

## Key invariants

- One row per normalised URL; a widely-shared link is fetched once.
- A card renders only when it was fetched successfully **and** has a title. A fetch can succeed
  against a page carrying no metadata at all, so status alone is not the test.
- Nothing in `LinkMetadata` is HTML, and `MetadataExtractor` performs no I/O.
- `link_cards.image_file_id` is a signed `INT` to match `files.id` — `foreignId()` emits
  `BIGINT UNSIGNED` and MySQL refuses the constraint. SQLite accepts either, so the mismatch would
  only surface on a real deployment.
- The image size check runs before the decode, enforced by test.

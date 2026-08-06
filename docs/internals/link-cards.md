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

## When a card is fetched

Posting is not the only moment one is needed: records written before the feature was switched on
have never been examined, and a card fetched a week ago has expired. Neither is reachable from a
write, so there are two triggers.

**On write**, the action that saved the body queues `SyncLinkCard`. That job re-reads the record, so
its final write is conditional on the body still being the one it parsed: an edit landing in between
clears the marker, and an unconditional write would attach the old body's card to the new text and
mark it examined. It also writes through the query builder rather than saving the model — even
`saveQuietly` bumps `updated_at`, and community topic and event lists are ordered by it, so a card
synced from someone opening an old post would float it back to the top of the board. **On read**,
[`LinkCardSync`](../../app/LinkCard/LinkCardSync.php) is called from the controller of a *detail*
page — after authorization, never from a serializer, and never from a list, where one page view
would queue a page's worth of jobs. Nothing runs inline; a page view never waits on the network.

The two jobs are split because they are keyed differently:

| | keyed by | does |
|---|---|---|
| `SyncLinkCard` | the record | reads the body, attaches the right card, queues a fetch if needed |
| `FetchLinkCard` | the URL | the one job that touches the network |

That split is what makes a link a thousand people posted cost one request.

`link_card_synced_at` distinguishes **"examined, has no link"** from **"never examined"**. Without
it the read path could not tell whether there is work to do, and a body with no URL would be
re-parsed on every view forever. It is also why turning the setting off and on again loses nothing:
records posted while it was off keep a null `link_card_synced_at`, so they are indistinguishable
from any other never-examined record when it returns.

### Two workers, one URL

A popular link arrives as many identical jobs, and a slow job can come back after a newer one has
already answered. Three mechanisms, each covering what the previous one does not:

1. **`ShouldBeUnique`** collapses the duplicates a burst produces, before they queue.
2. **A conditional UPDATE claims the fetch** (`LinkCard::claimFetch`). Of the jobs that do run, one
   proceeds and the rest return immediately.
3. **Every write back is fenced on the lease the claim returned** (`LinkCard::completeFetch`). This
   is the one that is easy to leave out, and the claim does not cover it: worker A takes the lease
   and stalls, the lease expires, worker B claims and finishes — and A, returning at last, would
   overwrite B's newer result. The fence makes A's write match nothing and be dropped. An image A had
   already stored is deleted at that point; it is referenced by nothing.

The claim also carries the *state* it is claiming, not only the timing. `ShouldBeUnique` has a
window, so a duplicate delayed past it arrives after the first job has succeeded — lease released,
card fresh — and would otherwise claim it and fetch the same URL again for nothing.

Whether a card is due is one predicate, `LinkCard::isDueForFetch`, used by the queueing side, the
read path and the claim alike. Written separately they disagreed: `isStale()` alone reads a *failed*
card as stale forever, since failures carry no expiry, so every new record mentioning a dead URL
would queue a fetch straight through the backoff meant to prevent exactly that.

A failed card is governed by `next_attempt_at` rather than by expiry, so a page that is simply gone
stops costing anything quickly. The backoff doubles and is clamped: a URL that has failed ten times
is not going to start working on a schedule.

## Pieces

| | |
|---|---|
| [`LinkUrl`](../../app/LinkCard/LinkUrl.php) | Normalises a URL into the key a card is stored under; resolves references found in a page |
| [`Encoding`](../../app/LinkCard/Encoding.php) | Converts a fetched page to UTF-8 |
| [`MetadataExtractor`](../../app/LinkCard/MetadataExtractor.php) | Markup in, `LinkMetadata` out. Pure — it never fetches |
| [`OembedClient`](../../app/LinkCard/OembedClient.php) | Calls a discovered oEmbed endpoint for the structured fields only |
| [`LinkCardImage`](../../app/LinkCard/LinkCardImage.php) | Downloads the card image and stores it as a local `File` |
| [`LinkCard`](../../app/Models/LinkCard.php) | The cached row, one per normalised URL; owns the lease and the backoff |
| [`LinkCardSync`](../../app/LinkCard/LinkCardSync.php) | Starts work from a page view |
| [`LinkCardSettings`](../../app/LinkCard/LinkCardSettings.php) | The one place the on/off setting is read |
| `SyncLinkCard` / `FetchLinkCard` | The two jobs (`app/Jobs`) |

## What normalisation may and may not do

Two spellings of one page should share a card; two different pages must never collide. That
asymmetry decides everything:

- **Normalised**, because none of it changes which resource is addressed: scheme and host case,
  default port, trailing dot, IDN → Punycode, and the fragment (which never reaches the server).
- **Kept in full: the query, including an empty one.** Plenty of sites put the article id there, so
  stripping or reordering it would merge unrelated pages into one card; and a server can distinguish
  `/a` from `/a?`. Tracking parameters therefore split the cache, which is the safe direction to be
  wrong in.
- `http` and `https` stay distinct — different origins, and the fetcher treats them as such.
- Ports are restricted to 80 and 443, matching `SafeHttpFetcher`. Accepting more would only mint
  cards for URLs the fetcher then refuses.

## Encoding is not an edge case

Shift_JIS, EUC-JP and ISO-2022-JP pages are still numerous, and the parser assumes UTF-8. Getting
this wrong does not fail loudly; it stores a title of mojibake.

The order in [`Encoding`](../../app/LinkCard/Encoding.php) is the design, and each step is there
because the obvious alternative is wrong in a specific way:

1. **ISO-2022-JP first, when declared.** It encodes Japanese entirely within the ASCII byte range
   using escape sequences, so it *passes* a UTF-8 validity check — returning early there leaves a
   title full of raw `ESC $ B`. It is the one legacy encoding UTF-8 validity cannot rule out.
   The condition is that it was *declared* ISO-2022-JP and actually contains a designation
   sequence — not that it is valid. A body cut mid-sequence is invalid yet still ISO-2022-JP, and
   every byte remains ASCII, so a validity test would fall through and return the escapes raw. A
   declaration with no escapes anywhere is a mislabel, and treated as the UTF-8 it validates as.
2. **Then strict UTF-8.** Asking instead whether the *declared* charset fits does not work: almost
   any UTF-8 Japanese text is also a valid CP932 sequence, so a page mislabelled Shift_JIS by a CMS
   template answers yes and is converted into mojibake. UTF-8's structure is strict and Shift_JIS
   lead bytes are invalid in it, so a genuine legacy page fails here and falls through.
3. **Then the declaration, then detection.**
4. **Then the declaration again, with substitution.** A body cut mid-character by the read cap is
   valid in nothing, and a blanket UTF-8-from-UTF-8 at that point replaces every multi-byte
   character in the whole prefix (`日本語` becomes `???{??`). Converting from the declared charset
   replaces only the incomplete tail.

Both declaration spellings are read: `<meta charset>` and the older
`<meta http-equiv="Content-Type" content="...; charset=...">`, which is what these pages carry.

## The image is copied, and the order of checks is the safety

Card images are downloaded and stored as local `File` rows rather than hot-linked, so a reader's
browser never contacts the linked site, the card survives the far end moving or blocking referrers,
and the existing thumbnail pipeline serves it.

**They carry no explicit visibility, and are therefore not yet deliverable.** Marking them public
would serve them from the login-free route to anyone holding the token, and a link card attaches to
friends-only diaries and private messages as readily as to open ones. The source URL is no evidence
to the contrary: normalisation keeps the query, so the image behind a signed or expiring link is
copied too, and a permanent public copy outlives both that expiry and the body's own visibility rule.
So the row is stored fail-closed and delivery is designed against the body that references it.

```
byte cap → finfo (real media type) → animation check → header dimensions
         → side and pixel limits → decode → store
```

Everything before the decode exists because a decoder allocates roughly width × height × 4 bytes
**per frame** before anything can inspect the result, and an out-of-memory kill is not catchable. So
the size must be known bounded from data that is cheap to read:

- **Total pixels, not just each side.** The per-side limit alone permits 5000 × 5000 = 100 MB
  decoded, enough to end a 128 MB worker by itself.
- **No animation.** Frame count is bounded by neither the wire size nor the dimensions, and
  Intervention decodes animations by default, so a few-kilobyte GIF can hold hundreds of full-size
  allocations. A card shows one still picture, so these are refused outright.

  [`ImageContainer`](../../app/LinkCard/ImageContainer.php) answers that by **walking the container's
  own block lengths**, not by searching for a marker. A marker search is wrong in both directions: it
  misses real animations — a two-frame GIF needs no NETSCAPE loop extension, and an animated WebP can
  carry a padding chunk that pushes its `ANIM` header past any fixed window — and it invents them,
  since a still image's compressed data or metadata may contain the same bytes by chance.

  The question it answers is **"is this provably one still frame?"**, not "does this look animated?".
  Those are not complements: the second answers "no" both for a still image and for a parse that gave
  up, and the second case is the one an attacker constructs — pad a two-frame GIF with legal comment
  blocks until the walk runs out of budget and it reports a still image. So the block limit is a CPU
  bound only; reaching it, meeting an unknown block, or reading past the end all refuse the image.
  The cost is that an unusual but honest file is refused too, which loses a card its picture where
  the other direction loses the worker.

`LinkCardImageTest` asserts the decoder is called zero times for an oversized header, for an
over-budget pixel count and for an animated image — and that it *is* called for an acceptable one, so
none of these can be satisfied by never decoding at all.

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

## The setting

`SnsSettingKey::LinkCardEnabled`, **off unless an operator turns it on**. This is the only setting
that makes the site issue outbound requests, and it does so for URLs in friends-only and private
bodies as well as open ones — a decision about what this deployment tells the wider web, not a
display preference. OpenPNE 3's `enable_cmd` is not an ancestor: that embedded three named services
in the reader's browser, where this fetches arbitrary hosts from the server.

It is read through `LinkCardSettings` from three places, and all three have to agree or the switch
does not mean what it says: the read path (do not start work), the fetch job (do not make the
request, even if it was queued while the setting was on), and the renderer (do not show a card
fetched earlier).

The admin page states what turning it on does, because "show link previews" does not imply it: the
server reaches out to every linked page, from bodies only a few people can read as well as open
ones, and each destination learns the link was shared here.

## Key invariants

- One row per normalised URL; a widely-shared link is fetched once.
- A card renders only when it was fetched successfully **and** has a title. A fetch can succeed
  against a page carrying no metadata at all, so status alone is not the test.
- Nothing in `LinkMetadata` is HTML, and `MetadataExtractor` performs no I/O.
- Cleaning and column-width limits live in `LinkMetadata`'s constructor, not in its callers — there
  is more than one source of these fields, and a new one must not be able to arrive without them.
- Open Graph image groups are read in document order: `og:image` and `og:image:url` each open an
  object, a structured property belongs to the root preceding it, and the first object listed is the
  page's preferred one.
- Animation is determined by parsing the container, never by searching for a marker or by decoding.
- Card images have no explicit visibility, so they are undeliverable until delivery is designed
  against the referencing body.
- `link_cards.image_file_id` is a signed `INT` to match `files.id` — `foreignId()` emits
  `BIGINT UNSIGNED` and MySQL refuses the constraint. SQLite accepts either, so the mismatch would
  only surface on a real deployment.
- The size and animation checks run before the decode, enforced by test.
- What gets a card is exactly what the reader sees linked: extraction is dispatched per format
  (`BodyRenderer::urls`) alongside rendering, so a Markdown code span yields no card and a bare
  `www.` host gets the same scheme the renderer gives it.
- Only the first URL in a body becomes a card.
- A fetch result is written only under the lease that claimed it.
- The read trigger fires on detail pages only, and only ever queues work.
- Syncing a card never changes a record's `updated_at`, and never writes to a body it did not read.
- One predicate decides whether a fetch is due, shared by the queueing side, the read path and the
  claim.

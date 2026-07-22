# Body text & formats

Every user-entered body (diary, community topic/event, message, timeline, comments, profile
free text) renders through shared body components — never ad-hoc escaping at call sites. On
the server that is the `BodyRenderer` dispatch; on the Modern client a plain body renders
through the `UserText`/`linkify` pair (kept in lockstep with the server's plain renderer).
Three formats exist, carried per record in a `format` column on `diaries`,
`community_topics`, and `community_events` (other bodies are plain-only and carry no column):

| format | who writes it | renderer |
|---|---|---|
| `plain` (default) | everyone | [`BodyText`](../../app/Support/BodyText.php) — escape + bare-URL autolink + `nl2br` |
| `markdown` | opt-in via the compose toggle | [`MarkdownText`](../../app/Support/MarkdownText.php) |
| `op3` | the OpenPNE 3 upgrade only | [`Op3Text`](../../app/Support/Op3Text.php) |

[`BodyFormat`](../../app/Support/BodyFormat.php) is the enum;
[`BodyRenderer`](../../app/Support/BodyRenderer.php) is the only place that maps a format to a
renderer (`render`, `excerpt`, and `plainText` for text/plain mail).

## Render authority is the server

A plain body still renders client-side on the Modern surface
([`user-text.tsx`](../../resources/js/components/user-text.tsx) +
[`linkify.ts`](../../resources/js/lib/linkify.ts), kept in lockstep with `BodyText`) — React
escapes it, so no trusted HTML crosses the wire. For any non-plain format the **server** renders:
serializers expose `bodyHtml` (always `null` for plain), and
[`RichBody`](../../resources/js/components/rich-body.tsx) is the app's **sole
`dangerouslySetInnerHTML` site**. Classic prints the same `BodyRenderer` output raw through
[`<x-user-text>`](../../resources/views/components/user-text.blade.php). Nothing else may emit
raw user-derived HTML; `bodyHtml` must never be assembled from client input.

## `op3` — migration-only, frozen

The upgrade tags every migrated diary body `op3`; `Op3Text` ports OpenPNE 3's PC-mode
decoration (`<op:*>` tags → `span.op_*`, colors validated `#RRGGBB`, sizes clamped 1–7,
unbalanced tags closed). Its architecture is a tokenizer: op tags (raw and entity-escaped
forms) are cut out of the raw stored body first, and only the text between them goes through
the shared escape/autolink/nl2br. Escaping the whole body first would corrupt entity-stored
tags, and autolinking after span generation would double-escape URL ampersands. Intentional
deltas from OpenPNE 3 are documented in the class docblock and pinned by
[`Op3TextTest`](../../tests/Unit/Support/Op3TextTest.php).

`op3` is never authorable: Store/Update requests validate `format` ∈ `plain|markdown`, and the
update actions additionally refuse to change an op3 row's format regardless of input. The
compose forms render **no** format field for an op3 record (an absent field preserves the
stored format) — only a note that the entry keeps its OpenPNE 3 formatting.

Mobile-era carrier emoji (`[i:N]`-style codes) are not a render-time concern: the upgrade
rewrites them to Unicode once (`EmojiMap` / `EmojiTransform` under
[`app/Upgrade/Runner`](../../app/Upgrade/Runner)); emoji values are normalized to RGI
fully-qualified sequences precisely because the rewrite is irreversible, with a small
explicit `NON_EMOJI` allowlist for carrier glyphs that map to plain characters. Third-party
data attribution for the mapping lives in the repository [NOTICE](../../NOTICE).

## `markdown` — two independent safety belts

`MarkdownText` renders CommonMark plus a GFM subset (autolink, strikethrough, tables) with
raw HTML escaped (`html_input: escape`) and unsafe link schemes refused, then pipes the result
through a symfony/html-sanitizer allowlist. The layers are deliberately redundant: either one
alone keeps a hostile body inert. Notable choices, each pinned by
[`MarkdownTextTest`](../../tests/Unit/Support/MarkdownTextTest.php):

- **Images render as their escaped alt text**, via a renderer override. The sanitizer has no
  `img` in its allowlist, and letting the tag through only to drop it would silently discard
  the alt text too.
- **The sanitizer's default input cap is disabled.** It silently truncates past 20 KB; a safe
  65,535-byte body legitimately exceeds that once escaped. The bound lives upstream instead:
  the markdown-capable body rules (diary/topic/event) and the preview cap the **raw** input at
  the TEXT column's 65,535 bytes ([`MaxBytes`](../../app/Rules/MaxBytes.php) — bytes, not
  characters). Plain-only bodies keep their own rules (timeline stays `max:140` characters).
- Soft breaks render as `<br>`, so a markdown body keeps plaintext-like line behavior.

The compose forms post `format=plain|markdown`. The Classic form drives it from a checkbox paired
with a hidden `format=plain` field, because an unchecked checkbox posts nothing and an absent field
means "keep the stored format" on update. The Modern forms show a live preview through
`POST /compose/preview` ([`PreviewController`](../../app/Features/Compose/PreviewController.php),
authenticated + throttled), which runs the **identical** pipeline as a stored render — the
preview can never show markup the saved body would strip.

### Authoring: the input method

Modern presents one member-facing choice — the **input method**, behind the `…` on the body label
row — rather than the `mode × format` pair behind it. Its three values are exactly the three valid
states, and are also what
[`PreferenceKey::ComposeEditor`](../../app/Support/PreferenceKey.php) (default `Rich`) remembers:

| input method | editor | `format` |
|---|---|---|
| Use formatting buttons | WYSIWYG (rich) | `markdown` |
| Use Markdown | textarea + live preview | `markdown` |
| No formatting | textarea | `plain` |

Only an explicit pick persists the choice (`POST /compose/editor`, coalesced to the last one made);
opening a form never does. On open the **record's format wins** and the preference only picks the
editor within it, so a `plain` record always opens unformatted (never silently reparsed as
Markdown) and an `op3` record gets no control at all. Moving a stored record across `plain` ⇄
`markdown` is confirmed first, since it changes how the same bytes render. Resolution and the
transitions are [`editor-mode.ts`](../../resources/js/components/compose/editor-mode.ts); the
shared block is [`BodyField`](../../resources/js/components/compose/body-field.tsx).

An untouched body submits its form value unchanged — mounting the rich editor parses the stored
body but fires no change signal, and switching input methods never rewrites the value either —
and after an edit the serializer's canonical Markdown normalization is accepted. A fileless save
posts JSON, so it is byte-stable end to end; only a save with an image attached uses
`multipart/form-data`, whose browser encoding normalizes bare LF to CRLF (one byte per line break,
once; the renderer treats both identically). The rich editor's schema equals the server sanitizer
allowlist (authoring a construct the sanitizer would strip would lose it on save):
[`editor-extensions.ts`](../../resources/js/components/compose/editor-extensions.ts) is the SSoT.

## Excerpts and mail text

`BodyRenderer::excerpt` produces the single-line feed excerpt (display width 108, no
ellipsis): plain/op3 strip decoration tags (`BodyText::excerpt`), markdown flattens its
rendered HTML. `BodyRenderer::plainText` is the full-length flatten for text/plain mail:
newlines survive, markdown links keep their targets as `label (url)`, and autolinked URLs are
not duplicated. Notifications that embed a formatted body (topic/event posted) must use it —
raw markdown source must not reach a mail body.

## Key invariants

- One dispatch: server-produced trusted HTML comes only from `BodyRenderer`; excerpt/mail
  text come from its `excerpt`/`plainText`. The Modern plain path renders client-side
  (`UserText`, React-escaped) and carries no trusted HTML. No call site escapes or renders a
  body by hand.
- `bodyHtml` is null for plain, server-produced otherwise; `RichBody` is the only
  `dangerouslySetInnerHTML` sink.
- `op3` exists only on rows written by the upgrade; no request path can create or change it.
- Markdown output survives either safety layer being wrong; input size is bounded before the
  pipeline, not inside it.
- Styling: Modern uses the `.rich-body` block in [`app.css`](../../resources/css/app.css);
  Classic wraps markdown output in `.markdownBody` (emitted `@once` from `<x-user-text>`)
  because the OpenPNE 3 skin's reset flattens headings, list markers, and emphasis. The skin
  CSS itself is never edited.

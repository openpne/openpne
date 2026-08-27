# Typography: emphasis on the Modern surface

Font weight has two jobs and no others:

1. **Naming a region** — page titles, the top bar's label, group and card-band headings. Weight 600, through [`headingVariants`](../../resources/js/components/ui/heading.tsx).
2. **Marking unread state.** Weight 600.

Everything else is 400. A row's hierarchy comes from size and color instead: the line that identifies
the entry is the largest text in it, the context line above it is smaller, and metadata is smaller
still and muted. Weight is not a third axis on top of that — spend it on the two jobs and it keeps
meaning something.

## Why emphasis is binary

Latin glyphs render in Instrument Sans, which ships 400/500/600. Japanese never does: the `@font-face`
`unicode-range` covers Latin only, so CJK always falls back to the platform font — Hiragino Sans,
Noto Sans CJK, Yu Gothic — and which faces those ship varies by OS version. Put meaning on an
in-between step and the meaning becomes device-dependent: where a family has only 400 and 700 faces,
CSS font matching resolves 500 down to 400 and 600 up to 700.

So the scale is 400 and 600, and 600 rather than 700: on a two-face family it resolves to the heavier
one, so the step is always visible, without the extra weight 700 carries where the full ramp exists.
This is a product decision about how many roles to define, not a claim that intermediate faces are
unavailable — on iOS 18 and Android 15 they are.

## Heading roles

`headingVariants` is exported as a recipe as well as a `Heading` component, because a heading is not
always an `h1`/`h2`/`h3`: the top bar's label is a `span` and dialog titles are Radix primitives.
Those consume the recipe; document headings use the component, which keeps the heading level
independent of the visual rank.

The independence runs the other way too: an element can be a heading in the document without taking a
heading rank. A settings row's name is an `h3` — the page is worth navigating by heading — styled as
the row's content line, because that is what it is. A bare `h3` outside the recipe is that case, not
a missed migration.

| variant | size | used by |
|---|---|---|
| `display` | 24px | the one headline a screen leads with, one rank above `page` |
| `page` | 20px | page titles |
| `pageCompose` | 18px, 20px from `lg` | compose screens, where the sheet header sits right above the title |
| `group` | 18px | a heading that stands outside the cards it groups — the settings page's groups |
| `section` | 16px | a block within the content flow: a form section, a settings sub-section, a dialog title |
| `minor` | 14px | a card's title band, a rail heading, a heading nested inside a section |
| `label` | 12px, muted | a group label inside a compact widget — a menu's, a grid's |
| `bar` | 16px | the top bar's centered label and its scope name (chrome, outside the content ranks) |

Six content ranks rather than three, because that is what the screens use: the notification settings
page runs page title over section over nested heading, and collapsing the middle rank would leave two
of them on the same size with only a rule between. Weight is 600 at every rank — it says "this names
a region" — and the rank is the size.

`display` and `page` carry `break-words` so a long member or community name cannot clip. Color lives
on each variant rather than the base, so `label` can be muted without leaving two color utilities on
one element for the stylesheet's order to resolve.

## What the rule does not cover

- **Authored content** — `<strong>` in anything rendered through `RichBody`, and the `.rich-body`
  rules in `app.css`. That covers member bodies as well as the admin-written login message and policy
  pages: the writer's emphasis, not the interface's.
- **Identity marks** — [`BrandMark`](../../resources/js/components/brand-mark.tsx),
  [`BrandName`](../../resources/js/components/brand-name.tsx),
  [`InitialBadge`](../../resources/js/components/initial-badge.tsx). Site identity rather than text
  hierarchy.
- **The Classic surface and the admin panel.** Separate stacks.

## Key invariants

- **The migration is finished, so the guard is a complete gate**: a weight class anywhere outside the
  heading recipe, the unread helper and the three identity marks fails. When it does, there are two
  honest answers — the text names a region or marks unread and belongs in the recipe that owns that
  role, or it does not and the weight comes out. A component that genuinely needs to keep weight
  earns it through the on-device comparison (label against value, current against not-current,
  primary against secondary, in light and dark, including hover, focus and disabled) and is recorded
  with the state it was judged in and the axis it failed. Nothing has earned that yet.
- `FontWeightGuardTest` budgets every source file under `resources/js` by weight-class count and
  fails three ways: a weight in a file with no budget, a count above its budget, and a count below it
  (a budget lowered late stops being the source of truth).
- **A budgeted file is not a skipped file.** Owners get an exact count, not a pass, because the files
  that hold a role also hold ordinary text — `top-nav.tsx` carries the bar label beside a guest
  sign-in link. Waving the file through would let any later weight in it stay green.
- The guard reads Tailwind classes in `.ts` and `.tsx` — `.ts` because `compose/editor-extensions.ts`
  holds the class string the editable is rendered with — and covers every weight utility except
  `font-normal`, plus both arbitrary forms (`font-[550]`, `[font-weight:700]`). Plain CSS, inline
  styles and semantic `<strong>` are outside it by construction, so they stay a review question.
- The budgets are counts, which cannot see a removal and an addition inside one owner file cancelling
  out. Occurrence fingerprints would; move to those if that ever actually happens.

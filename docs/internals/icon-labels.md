# Naming icon-only controls

A control that paints a glyph and no word has to say what it does twice: once to a screen reader, and
once to the person looking at it. [`Tip`](../../resources/js/components/ui/tooltip.tsx) is both, from
one argument.

```tsx
<Tip label={t('Reply')}>
    <button type="button" onClick={onReply} className={ICON_BUTTON}>
        <Reply className="size-4" aria-hidden />
    </button>
</Tip>
```

The rule is per state, not per component: the same control can be icon-only in one look and carry its
word in another (nav-drawer.tsx's trigger), and only the wordless arm takes a `Tip`. Where a word is
already on screen, nothing is added — a floated copy of what the reader can see, or an `aria-label`
spelling something else over it, both say something the visible label does not.

`silent` is the exception that proves it. A control that collapses in place — the floating action
button, whose label animates its own width — cannot have the wrapper swapped in and out around it:
that changes the element type at that position, React rebuilds the pill, and the label jumps instead
of moving. So it stays wrapped in both states and the panel is what is held back.

## Why the label is one argument

`Tip` clones the child to set `aria-label`, rather than letting Radix's `Trigger asChild` merge it
down. Slot's merge lets the child's own props win, so a child that already carried an `aria-label`
would keep it, disagree with the panel beside it, and leave the tooltip, the screenshots and the
`getByRole('button', { name })` queries all green. Cloning makes the disagreement impossible; a child
that brought its own name throws in dev instead.

That guard sees the direct child's props and nothing deeper. An `aria-label` written inside a
component child is invisible to it — it catches a mistake, it does not prove there is none. The
injection is bounded the same way: wrap something that carries its props and ref to a DOM node. A
component child that drops them ends up with no name anywhere, and nothing throws for it.

## Why the panel is not a description

Radix associates the panel with its trigger through `aria-describedby`. Here the panel's text *is* the
name, so the association is dropped (`aria-describedby={undefined}` on the trigger) — kept, it reads
out as "Reply, button, Reply". Radix's own answer to the double announcement runs the other way: give
`Content` an `aria-label` and let the visible text stay a description. That is for a trigger named from
somewhere else; it is not this case, so the association goes rather than the text.

The child's own `aria-describedby` is untouched — it may point at an error or a hint that has nothing
to do with the tooltip.

## Why focus alone does not raise it

Radix opens the panel on any focus it did not see a pointer press for, and focus moves on its own
more often than a keyboard moves it: a dialog focuses its close control as it opens and its trigger
as it shuts. After a tap that meant "Close" floating in the nav sheet and "Menu" floating once it was
gone. So a focus raises the panel only when the browser draws a ring for it — `:focus-visible` — which
is the keyboard's focus and not a dialog's. The trigger's `onFocus` calls `preventDefault` otherwise,
the flag Radix's composed handler checks before opening.

## What is deliberately not covered

- **Touch.** No tooltip is raised by a finger, on any platform. This is why `label` lands on the
  element regardless: the control must be usable knowing only what it paints, and a long-press sheet
  is where a phone puts the words.
- **Disabled controls.** A `disabled` element takes no pointer events and no focus, so nothing is
  raised over one. Moving them to `aria-disabled` plus a click guard was considered and dropped: the
  behavior change is real and the reward is a control's own name, when what a disabled control needs
  said is *why it cannot be pressed* — a different feature.
- **`title=` for truncation.** The remaining native `title` attributes (nine-table, people-grid) give
  the full text of a line that had to be cut. That is a different job from naming a control, and it
  stays.

## Key invariants

- **One `TooltipProvider`, at the root** (app.tsx). Radix throws rather than warns when a tooltip
  finds none, so a component test that renders any adopting site must supply it —
  [`renderWithProviders`](../../resources/js/lib/test-render.tsx) is that one line. Per-tooltip
  providers would each keep their own `skipDelayDuration`, which is exactly the shared state that
  makes the second of two neighbouring icons open instantly.
- **The accessible name does not depend on the panel ever opening.** `tooltip.test.tsx` pins the name
  and the absent description in both states.
- **Only a visible focus raises the panel.** `tooltip.test.tsx` pins both arms: a `:focus-visible`
  focus opens it, one the browser draws no ring for does not.
- **Not machine-enforced.** "Icon-only" is not decidable from source: a glyph, a truncating label and
  a word are all children. A lint on new `title=` would be decidable but would fire on the truncation
  case above. So this is a review question, and the dev throw covers the one part of it that is
  mechanical — two names on one control.

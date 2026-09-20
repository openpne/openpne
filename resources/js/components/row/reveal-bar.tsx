import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The revealing states beat `pointer-fine:pointer-events-none` by selector specificity, so a bare
 * `pointer-events-auto` would tie it and leave the controls dead to every click
 * (docs/internals/reactions.md, "The row").
 */
export const REVEAL_BAR =
    'absolute right-2 -top-1 z-10 flex items-center gap-1 rounded-lg border border-border bg-card px-1 py-0.5 text-sm text-muted-foreground shadow-sm opacity-0 transition-opacity motion-reduce:transition-none pointer-fine:pointer-events-none group-hover:opacity-100 group-hover:pointer-events-auto group-has-[:focus-visible]:opacity-100 group-has-[:focus-visible]:pointer-events-auto has-[[aria-expanded=true]]:opacity-100 has-[[aria-expanded=true]]:pointer-events-auto has-[[data-ack]]:opacity-100 has-[[data-ack]]:pointer-events-auto pointer-coarse:sr-only pointer-coarse:focus-within:not-sr-only pointer-coarse:focus-within:absolute';

/** The classes a row carries to host the bar: the bar's anchor, and a lift above its neighbours while its controls are out. */
export const REVEAL_ROW = 'group relative hover:z-10 has-[:focus-visible]:z-10 has-[[aria-expanded=true]]:z-10 has-[[data-ack]]:z-10';

/** What a press must not start: the lens and the image menu a held finger raises would land on the sheet. */
export const PRESS_ROW = 'pointer-coarse:select-none pointer-coarse:[-webkit-touch-callout:none]';

/** The caller decides whether there is a bar at all: an empty frame over a row with no power over itself is the caller's mistake to avoid. */
export function RevealBar({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cn(REVEAL_BAR, className)}>{children}</div>;
}

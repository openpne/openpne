import { cva, type VariantProps } from 'class-variance-authority';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * The one place a heading's weight and size are decided. Weight has exactly two jobs on the Modern
 * surface — naming a region (this) and marking unread state — and everything else is 400, so a row's
 * hierarchy comes from size and color instead. Emphasis stays binary (400 / 600) because which faces
 * a Japanese fallback family ships varies by platform: put meaning on an in-between step and the
 * meaning itself becomes device-dependent. 600 over 700 because a family with only 400 and 700 faces
 * resolves 600 to the heavier one, so the step is always visible, without the extra weight 700 would
 * carry where the full ramp exists.
 *
 * Exported as a recipe as well as a component because a heading is not always an h1/h2/h3: the top
 * bar's label is a span, and dialog titles are Radix primitives. Those consume `headingVariants`
 * directly; document headings use {@link Heading}.
 *
 * The ranks are five sizes, 20 down to 12, because that is what the screens actually use: the
 * notification settings page runs page title over section over nested heading, and collapsing the
 * middle one would put two ranks on the same size with only a rule between them. Weight is 600 at
 * every rank — it says "this names a region", and the rank is the size.
 *
 * `page` carries `break-words` so a long member or group name can never clip the way it did
 * before #311 — a caller cannot forget it. Smaller ranks are left to wrap or truncate as their
 * container decides. Flex children still need their own `min-w-0`, which depends on the parent.
 */
// Color belongs to the variant, not the base: `label` is the one rank that is muted, and a base
// `text-foreground` plus a variant `text-muted-foreground` would leave two color utilities on the
// element with the stylesheet's order, not the class list's, deciding — a conflict a bare
// `headingVariants(...)` call (no twMerge) would resolve the wrong way half the time.
export const headingVariants = cva('font-semibold', {
    variants: {
        variant: {
            /** Page title. */
            page: 'text-xl break-words text-foreground',
            /** Page title on a compose screen: smaller on the phone, where the sheet header sits right above it (#521). */
            pageCompose: 'text-lg break-words text-foreground lg:text-xl',
            /** Names a set of cards from outside them — the settings page's groups. */
            group: 'text-lg text-foreground',
            /** Names a block within the content flow: a form section, a settings sub-section, a dialog. */
            section: 'text-base text-foreground',
            /** The smallest heading rank: a card's title band, a rail heading, one nested inside a section. */
            minor: 'text-sm text-foreground',
            /** A group label inside a compact widget — a menu's, a grid's. Muted: it labels rather than announces. */
            label: 'text-xs text-muted-foreground',
            /** The top bar's centered label. Chrome, so it sits outside the content ranks. */
            bar: 'text-base text-foreground',
        },
    },
    defaultVariants: { variant: 'page' },
});

type HeadingProps = ComponentProps<'h1'> &
    VariantProps<typeof headingVariants> & {
        /** Heading level. Kept independent of `variant` so document structure and visual rank can
         *  disagree — a nested h3 may still want the section look. */
        as?: 'h1' | 'h2' | 'h3';
    };

export function Heading({ as: Tag = 'h1', variant, className, ...props }: HeadingProps) {
    return <Tag className={cn(headingVariants({ variant }), className)} {...props} />;
}

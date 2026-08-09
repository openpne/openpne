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
 * `page` carries `break-words` so a long member or community name can never clip the way it did
 * before #311 — a caller cannot forget it. `section` is left to wrap or truncate as its band decides.
 * Flex children still need their own `min-w-0`, which depends on the parent, not on this.
 */
export const headingVariants = cva('font-semibold text-foreground', {
    variants: {
        variant: {
            /** Page title. */
            page: 'text-xl break-words',
            /** Page title on a compose screen: smaller on the phone, where the sheet header sits right above it (#521). */
            pageCompose: 'text-lg break-words lg:text-xl',
            /** The top bar's centered label. */
            bar: 'text-base',
            /** A group of cards/sections that stands outside them. */
            group: 'text-lg',
            /** The band at the top of a card, and rail headings. */
            section: 'text-sm',
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

import { render, screen } from '@testing-library/react';
import { describe, expect, test } from 'vitest';
import { Card } from '@/components/card';
import { dropdownMenuItemVariants } from '@/components/ui/dropdown-menu';
import { Heading, headingVariants } from '@/components/ui/heading';
import { Panel } from '@/components/ui/surface';
import { cn } from '@/lib/utils';

// Class sets before each axis existed plus what a call site added by hand, compared as sets without
// the weight class, whose placement FontWeightGuardTest governs.
const HEADING = {
    display: 'text-2xl break-words text-foreground',
    page: 'text-xl break-words text-foreground',
    group: 'text-lg text-foreground',
    section: 'text-base text-foreground',
    label: 'text-xs text-muted-foreground',
};
const ITEM = 'flex min-h-11 cursor-pointer select-none items-center gap-3 rounded-lg px-3 text-sm text-foreground outline-none transition focus:bg-accent focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50';
const CARD = 'overflow-hidden rounded-card border border-border bg-card text-card-foreground shadow-card';
const classes = (s: string) => new Set(s.split(/\s+/).filter(Boolean));
const withoutWeight = (s: string) => new Set([...classes(s)].filter((c) => !c.startsWith('font-')));
const COLORS = ['text-foreground', 'text-muted-foreground', 'text-destructive'];

describe('each axis renders the class set its call sites used to add by hand', () => {
    test.each([
        ['a danger page title', cn(HEADING.page, 'text-destructive'), cn(headingVariants({ variant: 'page', tone: 'destructive' }))],
        ['a danger group', cn(HEADING.group, 'text-destructive'), cn(headingVariants({ variant: 'group', tone: 'destructive' }))],
        ['a divided section', cn(HEADING.section, 'border-b border-border pb-2'), cn(headingVariants({ variant: 'section', divided: true }))],
        ['a divided label', cn(HEADING.label, 'border-b border-border pb-2'), cn(headingVariants({ variant: 'label', divided: true }))],
        ['the default heading', HEADING.page, cn(headingVariants())],
        ['a display heading', HEADING.display, cn(headingVariants({ variant: 'display' }))],
        ['a destructive item', cn(ITEM, 'text-destructive focus:bg-destructive/10 focus:text-destructive'), cn(dropdownMenuItemVariants({ variant: 'destructive' }))],
        ['the default item', ITEM, cn(dropdownMenuItemVariants())],
    ])('%s', (_name, before, after) => {
        expect(withoutWeight(after)).toEqual(withoutWeight(before));
    });

    // Read off the raw recipe, not cn(): a consumer of the recipe has no twMerge to drop a second color.
    test('a heading recipe emits exactly one color class at every rank and tone', () => {
        for (const variant of ['display', 'page', 'pageCompose', 'group', 'section', 'minor', 'label', 'bar'] as const) {
            for (const tone of ['default', 'destructive'] as const) {
                const found = [...classes(headingVariants({ variant, tone }))].filter((c) => COLORS.includes(c));
                expect(found, `${variant}/${tone}`).toHaveLength(1);
            }
        }
    });
});

describe('Heading, Card and Panel turn the tone into classes rather than an attribute', () => {
    test('Heading', () => {
        render(
            <Heading as="h2" variant="section" tone="destructive" divided>
                x
            </Heading>,
        );
        const el = screen.getByRole('heading');
        expect(classes(el.className)).toEqual(classes(cn(headingVariants({ variant: 'section', tone: 'destructive', divided: true }))));
        expect(el.hasAttribute('tone')).toBe(false);
        expect(el.hasAttribute('divided')).toBe(false);
    });

    test('Card and Panel', () => {
        const { container } = render(
            <>
                <Card tone="destructive">x</Card>
                <Panel tone="destructive">y</Panel>
            </>,
        );
        for (const el of container.querySelectorAll(':scope > div')) {
            expect(classes(el.className)).toEqual(classes(cn(CARD, 'border-destructive/40')));
            expect(el.hasAttribute('tone')).toBe(false);
        }
        expect(container.querySelectorAll(':scope > div')).toHaveLength(2);
    });
});

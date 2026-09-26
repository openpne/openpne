import { render, screen } from '@testing-library/react';
import { describe, expect, test } from 'vitest';
import { ActionLink } from '@/components/ui/action-link';
import { Button, buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';

// The class set each axis stands for, written as the base, a variant, a size and the extras a call
// site used to add by hand, compared as a set since cn() may reorder.
const BASE =
    'inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-field text-sm transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:pointer-events-none disabled:opacity-50 active:scale-[0.98]';
const OLD = {
    default: 'bg-primary text-primary-foreground hover:bg-primary/90',
    secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
    outline: 'border border-input bg-background hover:bg-accent hover:text-accent-foreground',
    ghost: 'hover:bg-accent hover:text-accent-foreground',
    sizeDefault: 'min-h-11 px-5 py-2',
    sm: 'min-h-9 px-3',
    icon: 'size-11',
};
const classes = (s: string) => new Set(s.split(/\s+/).filter(Boolean));

describe('each axis renders the class set its call sites used to add by hand', () => {
    test.each([
        ['a load-more whose line is its bottom edge', cn(BASE, OLD.ghost, OLD.sm, 'w-full rounded-none border-b border-border py-3 text-link hover:bg-muted hover:text-link sm:px-5'), cn(buttonVariants({ variant: 'row', size: 'row', edge: 'bottom' }))],
        ['a load-more whose line is its top edge', cn(BASE, OLD.ghost, OLD.sm, 'w-full rounded-none border-t border-border py-3 text-link hover:bg-muted hover:text-link sm:px-5'), cn(buttonVariants({ variant: 'row', size: 'row', edge: 'top' }))],
        ['a floating jump', cn(BASE, OLD.secondary, OLD.sm, 'pointer-events-auto shadow-md'), cn(buttonVariants({ variant: 'secondary', size: 'sm', elevated: true }), 'pointer-events-auto')],
        ['a muted icon', cn(BASE, OLD.ghost, OLD.icon, 'shrink-0 text-muted-foreground'), cn(buttonVariants({ variant: 'ghost', size: 'icon', tone: 'muted' }), 'shrink-0')],
        ['a pill', cn(BASE, OLD.default, OLD.sm, 'rounded-full'), cn(buttonVariants({ size: 'sm', shape: 'pill' }))],
        ['an outline pill that wraps', cn(BASE, OLD.outline, OLD.sm, 'rounded-full whitespace-normal text-center'), cn(buttonVariants({ variant: 'outline', size: 'sm', shape: 'pill' }), 'whitespace-normal text-center')],
        ['the default', cn(BASE, OLD.default, OLD.sizeDefault), cn(buttonVariants())],
    ])('%s', (_name, before, after) => {
        expect(classes(after)).toEqual(classes(before));
    });

    test('a button carries exactly one radius class', () => {
        for (const props of [{}, { shape: 'pill' as const }, { variant: 'row' as const, size: 'row' as const }]) {
            const radii = [...classes(cn(buttonVariants(props)))].filter((c) => c.startsWith('rounded-'));
            expect(radii).toHaveLength(1);
        }
    });
});

// A prop the component does not take out of `...props` lands on the element as an attribute and
// puts no class on it, which the types do not catch.
describe('Button and ActionLink turn every axis into classes rather than attributes', () => {
    test('Button', () => {
        render(
            <Button variant="secondary" size="sm" edge="bottom" shape="pill" tone="muted" elevated>
                x
            </Button>,
        );
        const button = screen.getByRole('button');

        expect(classes(button.className)).toEqual(classes(cn(buttonVariants({ variant: 'secondary', size: 'sm', edge: 'bottom', shape: 'pill', tone: 'muted', elevated: true }))));
        for (const axis of ['variant', 'size', 'edge', 'shape', 'tone', 'elevated']) {
            expect(button.hasAttribute(axis)).toBe(false);
        }
    });

    test('ActionLink', () => {
        render(
            <ActionLink href="/x" variant="outline" size="sm" shape="pill" tone="muted" elevated edge="top">
                x
            </ActionLink>,
        );
        const link = screen.getByRole('link');

        expect(classes(link.className)).toEqual(classes(cn(buttonVariants({ variant: 'outline', size: 'sm', shape: 'pill', tone: 'muted', elevated: true, edge: 'top' }))));
        for (const axis of ['variant', 'size', 'edge', 'shape', 'tone', 'elevated']) {
            expect(link.hasAttribute(axis)).toBe(false);
        }
    });
});

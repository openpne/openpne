import { render, screen } from '@testing-library/react';
import { describe, expect, test } from 'vitest';
import { Input, inputVariants } from '@/components/ui/input';
import { Select, selectVariants } from '@/components/ui/select';
import { Textarea, textareaVariants } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

// The class set each variant stands for, written as the base with its radius plus what a call site
// used to add by hand, compared as a set since cn() may reorder.
const FIELD =
    'flex min-h-11 w-full min-w-0 rounded-field border border-field-border bg-field px-3 py-2 text-base text-foreground shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-destructive aria-[invalid=true]:ring-2 aria-[invalid=true]:ring-destructive/30 md:text-sm';
const SELECT =
    'flex min-h-11 w-full rounded-field border border-field-border bg-field px-3 py-2 text-base text-foreground shadow-sm transition-colors focus-visible:border-ring focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-destructive aria-[invalid=true]:ring-2 aria-[invalid=true]:ring-destructive/30 md:text-sm';
const TEXTAREA =
    'flex min-h-24 w-full rounded-field border border-field-border bg-field px-3 py-2 text-base text-foreground shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-destructive aria-[invalid=true]:ring-2 aria-[invalid=true]:ring-destructive/30 md:text-sm';
const classes = (s: string) => new Set(s.split(/\s+/).filter(Boolean));

describe('each variant renders the class set its call sites used to add by hand', () => {
    test.each([
        ['a search field', cn(FIELD, 'rounded-full pr-11 pl-5'), cn(inputVariants({ variant: 'search' }))],
        ['a pill field', cn(FIELD, 'rounded-full px-5'), cn(inputVariants({ variant: 'pill' }))],
        ['a read-only field', cn(FIELD, 'bg-muted text-muted-foreground'), cn(inputVariants({ variant: 'readonly' }))],
        ['the default field', FIELD, cn(inputVariants())],
        ['a pill select', cn(SELECT, 'w-auto rounded-full pl-5'), cn(selectVariants({ variant: 'pill' }), 'w-auto')],
        ['a compact select', cn(SELECT, 'min-h-7 w-auto shrink-0 px-2 py-0.5 text-sm shadow-none'), cn(selectVariants({ size: 'compact' }), 'w-auto shrink-0')],
        ['a chat textarea', cn(TEXTAREA, 'max-h-40 min-h-11 resize-none overflow-y-auto rounded-2xl py-2.25 leading-6 placeholder:overflow-hidden placeholder:text-ellipsis placeholder:whitespace-nowrap'), cn(textareaVariants({ variant: 'chat' }), 'max-h-40 min-h-11 resize-none overflow-y-auto')],
        ['the default textarea', TEXTAREA, cn(textareaVariants())],
    ])('%s', (_name, before, after) => {
        expect(classes(after)).toEqual(classes(before));
    });

    test('a control carries exactly one radius class', () => {
        for (const s of [cn(inputVariants()), cn(inputVariants({ variant: 'search' })), cn(selectVariants({ variant: 'pill' })), cn(textareaVariants({ variant: 'chat' }))]) {
            expect([...classes(s)].filter((c) => c.startsWith('rounded-'))).toHaveLength(1);
        }
    });
});

// A prop the component does not take out of `...props` lands on the element as an attribute and
// puts no class on it, which the types do not catch.
describe('Input, Select and Textarea turn the variant into classes rather than an attribute', () => {
    test('Input', () => {
        render(<Input variant="search" aria-label="q" />);
        const el = screen.getByRole('textbox', { name: 'q' });
        expect(classes(el.className)).toEqual(classes(cn(inputVariants({ variant: 'search' }))));
        expect(el.hasAttribute('variant')).toBe(false);
    });

    test('Select', () => {
        render(
            <Select variant="pill" size="compact" aria-label="s">
                <option>x</option>
            </Select>,
        );
        const el = screen.getByRole('combobox');
        expect(classes(el.className)).toEqual(classes(cn(selectVariants({ variant: 'pill', size: 'compact' }))));
        expect(el.hasAttribute('variant')).toBe(false);
        expect(el.hasAttribute('size')).toBe(false);
    });

    test('Textarea', () => {
        render(<Textarea variant="chat" aria-label="t" />);
        const el = screen.getByRole('textbox', { name: 't' });
        expect(classes(el.className)).toEqual(classes(cn(textareaVariants({ variant: 'chat' }))));
        expect(el.hasAttribute('variant')).toBe(false);
    });
});

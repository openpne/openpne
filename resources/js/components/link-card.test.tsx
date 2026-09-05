import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, test } from 'vitest';
import { HERO_SIZES } from './image-grid';
import { LinkCard, type LinkCardData } from './link-card';

afterEach(cleanup);

const base: LinkCardData = {
    url: 'https://www.example.com/article',
    title: 'A title from the page',
    description: 'What the page says it is about.',
    siteName: 'Example',
    domain: 'example.com',
    layout: 'compact',
    imageUrl: '/linkCard/diary/1/img/png/w120_h120_sq/a.png',
    imageWidth: 120,
    imageHeight: 120,
    fitSources: [],
};

const wide: LinkCardData = {
    ...base,
    layout: 'wide',
    imageWidth: 1200,
    imageHeight: 630,
    fitSources: [
        { url: '/linkCard/diary/1/img/png/w320_h320/a.png', box: 320 },
        { url: '/linkCard/diary/1/img/png/w640_h640/a.png', box: 640 },
        { url: '/linkCard/diary/1/img/png/w1200_h1200/a.png', box: 1200 },
    ],
};

/** The card's own parts in the order they are drawn, ignoring how they are nested. */
function order(container: HTMLElement): string[] {
    return [...container.querySelectorAll('img, p')].map((node) => (node.tagName === 'IMG' ? 'image' : (node.textContent ?? '')));
}

test('the wide shape reads host, title, description, then the picture', () => {
    const { container } = render(<LinkCard card={wide} />);

    expect(order(container)).toEqual(['example.com', 'A title from the page', 'What the page says it is about.', 'image']);
});

test('the compact shape puts the picture beside the words, host first among them', () => {
    const { container } = render(<LinkCard card={base} />);

    expect(order(container)).toEqual(['image', 'example.com', 'A title from the page', 'What the page says it is about.']);
});

test('the host is drawn before the title, which is the part that can claim anything', () => {
    // Both shapes, because the reason is about trust rather than layout.
    for (const card of [base, wide]) {
        cleanup();
        const { container } = render(<LinkCard card={card} />);
        const text = [...container.querySelectorAll('p')].map((p) => p.textContent);

        expect(text.indexOf('example.com')).toBeLessThan(text.indexOf('A title from the page'));
    }
});

test('the wide picture offers every rung, with the widths it will really be served at', () => {
    const { container } = render(<LinkCard card={wide} />);
    const image = container.querySelector('img');

    // 1200x630 fits a 320 box at 320 and a 640 box at 640, and the 1200 box cannot enlarge it past
    // its own width — the descriptors are the real widths, not the boxes asked for.
    expect(image?.getAttribute('srcset')).toBe(
        '/linkCard/diary/1/img/png/w320_h320/a.png 320w, /linkCard/diary/1/img/png/w640_h640/a.png 640w, /linkCard/diary/1/img/png/w1200_h1200/a.png 1200w',
    );
});

test("a wide picture is held to the box a member's own picture gets in a comment or a chat row", () => {
    // The formula the image grid's `boxed` hero writes, with the banner ratio in place of the
    // picture's own: the column, the box (24rem by 20rem) and the source's size — so a 300px picture
    // is never enlarged either.
    const { container } = render(<LinkCard card={{ ...wide, imageWidth: 300, imageHeight: 200 }} />);
    const image = container.querySelector('img');

    expect(image?.style.maxWidth).toBe('min(100%, 24rem, 300px, calc(20rem * (1.91)))');
    expect(image?.getAttribute('sizes')).toBe(HERO_SIZES.boxed);
});

test('the wide picture sits in with the words rather than bleeding to the frame', () => {
    // Narrower than the card on any desktop column, so where it sits is a decision: with the words.
    const { container } = render(<LinkCard card={wide} />);

    expect(container.querySelector('img')?.parentElement).toBe(screen.getByText('A title from the page').parentElement);
});

test('the compact picture stretches to the height of the words, so nothing is left under it', () => {
    const { container } = render(<LinkCard card={base} />);

    // The mechanism, not the symptom: a fixed square would leave the gap the stretch removes.
    expect(container.querySelector('img')?.className).toContain('self-stretch');
    expect(container.querySelector('img')?.className).not.toContain('size-24');
});

test('a card carries no width of its own, so it is as wide as the words it belongs to', () => {
    // The regression this catches is a cap coming back: one did, and left a 384px card under 550px
    // of message text with its title wrapping inside the narrower box.
    for (const card of [wide, base]) {
        cleanup();
        const { container } = render(<LinkCard card={card} />);

        expect(container.querySelector('a')?.className).not.toMatch(/\bmax-w-/);
        expect(container.querySelector('a')?.className).not.toMatch(/\bw-\[/);
    }
});

test('a wide card with no ladder falls back to the shape that has a picture to draw', () => {
    // The server ships the ladder with the shape, so this is defence against a payload that lost one
    // half — never a blank space where the picture was.
    const { container } = render(<LinkCard card={{ ...wide, fitSources: [] }} />);

    expect(order(container)[0]).toBe('image');
    expect(screen.getByText('A title from the page')).toBeTruthy();
});

test('no card draws nothing', () => {
    const { container } = render(<LinkCard card={null} />);

    expect(container.innerHTML).toBe('');
});

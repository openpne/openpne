import { cleanup, render } from '@testing-library/react';
import { afterEach, expect, test, vi } from 'vitest';
import { fakeT } from '@/lib/test-i18n';
import { boxedPictureMaxWidth, type GridImage, HERO_SIZES, ImageGrid } from './image-grid';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

afterEach(cleanup);

const hero = (width: number, height: number): GridImage => ({
    id: 1,
    url: '/files/1',
    thumbnailUrl: '/files/1/w120_h120_sq',
    fitSources: [{ url: '/files/1/w640_h640', box: 640 }],
    cropSources: {},
    width,
    height,
});

test('a lone boxed picture is held to its box by the width formula the link card shares', () => {
    // The literal, not the helper: the helper is what a card reads too, so the string itself is the
    // contract. Landscape, portrait and square, because the height term binds only on the tall one.
    const shapes: Array<[number, number]> = [
        [1200, 630],
        [300, 900],
        [640, 640],
    ];
    for (const [w, h] of shapes) {
        cleanup();
        const { container } = render(<ImageGrid images={[hero(w, h)]} variant="boxed" />);
        const box = container.querySelector('button');

        expect(box?.style.maxWidth).toBe(`min(100%, 24rem, ${w}px, calc(20rem * (${w} / ${h})))`);
        expect(box?.style.maxWidth).toBe(boxedPictureMaxWidth(w, `${w} / ${h}`));
        expect(box?.style.aspectRatio).toBe(`${w} / ${h}`);
        expect(container.querySelector('img')?.getAttribute('sizes')).toBe(HERO_SIZES.boxed);
    }
});

test('a lone post picture is capped by the viewport, not by the box', () => {
    const { container } = render(<ImageGrid images={[hero(1200, 630)]} variant="post" />);

    expect(container.querySelector('button')?.style.maxWidth).toBe('min(100%, 1200px, calc(min(70vh, 32rem) * (1200 / 630)))');
    expect(container.querySelector('img')?.getAttribute('sizes')).toBe(HERO_SIZES.post);
});

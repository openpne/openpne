import { act, cleanup, render } from '@testing-library/react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { fakeT } from '@/lib/test-i18n';
import { boxedPictureMaxWidth, type GridImage, HERO_SIZES, ImageGrid } from './image-grid';

// useT reads the Inertia page for its term map, which a component test has no page to give it.
vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

// The autoplay switch arrives as a shared prop; `page` is what this test's viewer was served.
const page: { autoplayAnimations?: boolean } = {};
vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: page }) }));

// A matchMedia whose answer and listeners the test holds, standing in for the OS reduced-motion preference.
let reduceMotion = false;
const listeners = new Set<() => void>();
beforeEach(() => {
    reduceMotion = false;
    listeners.clear();
    delete page.autoplayAnimations;
    vi.stubGlobal('matchMedia', (query: string) => ({
        media: query,
        get matches() {
            return reduceMotion;
        },
        addEventListener: (_: string, fn: () => void) => listeners.add(fn),
        removeEventListener: (_: string, fn: () => void) => listeners.delete(fn),
    }));
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const hero = (width: number, height: number): GridImage => ({
    id: 1,
    url: '/files/1',
    thumbnailUrl: '/files/1/w120_h120_sq',
    fitSources: [{ url: '/files/1/w640_h640', box: 640 }],
    cropSources: {},
    width,
    height,
    animatedSources: [],
});

const animatedHero = (): GridImage => ({
    ...hero(1200, 630),
    fitSources: [
        { url: '/files/1/w320_h320', box: 320 },
        { url: '/files/1/w640_h640', box: 640 },
    ],
    animatedSources: [
        { url: '/files/1/w320_h320_a', box: 320 },
        { url: '/files/1/w640_h640_a', box: 640 },
    ],
});

/** Every URL the rendered pictures would fetch. */
function requested(container: HTMLElement): string[] {
    return Array.from(container.querySelectorAll('img')).flatMap((img) => [
        img.getAttribute('src') ?? '',
        ...(img.getAttribute('srcset') ?? '').split(',').map((c) => c.trim().split(' ')[0] ?? ''),
    ]);
}

test('a lone boxed picture is held to its box by the width formula the link card shares', () => {
    // The literal rather than the helper, which a card reads too, so the string itself is the
    // contract; landscape, portrait and square, because the height term binds only on the tall one.
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

test('the hero plays when the member has autoplay on and the OS asks for no less motion', () => {
    page.autoplayAnimations = true;
    const { container } = render(<ImageGrid images={[animatedHero()]} variant="post" />);

    const urls = requested(container);
    expect(urls).toContain('/files/1/w640_h640_a');
    expect(urls.every((url) => url === '' || url.endsWith('_a'))).toBe(true);
});

test('with autoplay off no picture on the page asks for an animated variant', () => {
    page.autoplayAnimations = false;
    const { container } = render(<ImageGrid images={[animatedHero()]} variant="post" />);

    const urls = requested(container);
    expect(urls).toContain('/files/1/w640_h640');
    expect(urls.some((url) => url.endsWith('_a'))).toBe(false);
});

test('the OS reduced-motion preference wins over the switch, from the first render on', () => {
    page.autoplayAnimations = true;
    reduceMotion = true;
    const { container } = render(<ImageGrid images={[animatedHero()]} variant="post" />);

    expect(requested(container).some((url) => url.endsWith('_a'))).toBe(false);
});

test('lifting the reduced-motion preference while the page is up starts the hero', () => {
    page.autoplayAnimations = true;
    reduceMotion = true;
    const { container } = render(<ImageGrid images={[animatedHero()]} variant="post" />);
    expect(requested(container).some((url) => url.endsWith('_a'))).toBe(false);

    reduceMotion = false;
    act(() => listeners.forEach((fn) => fn()));

    expect(requested(container).some((url) => url.endsWith('_a'))).toBe(true);
});

test('a hero with no animated ladder is a still whatever the viewer takes', () => {
    page.autoplayAnimations = true;
    const { container } = render(<ImageGrid images={[hero(1200, 630)]} variant="post" />);

    expect(requested(container)).toContain('/files/1/w640_h640');
    expect(requested(container).some((url) => url.endsWith('_a'))).toBe(false);
});

test('a guest — no switch in the props — is a still, and an empty set renders nothing', () => {
    const animated = render(<ImageGrid images={[animatedHero()]} variant="post" />);
    expect(requested(animated.container).some((url) => url.endsWith('_a'))).toBe(false);

    cleanup();
    // The hooks run before the early return, so an empty set must neither throw nor paint.
    const empty = render(<ImageGrid images={[]} variant="post" />);
    expect(empty.container.innerHTML).toBe('');
});

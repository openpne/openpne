import { cleanup, render } from '@testing-library/react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { fakeT } from '@/lib/test-i18n';
import { ImagesField, shrink } from './images-field';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

// The upload policy is a shared prop; this is what the server shipped to the page under test.
const shipped = () => ({
    accept: 'image/jpeg,image/png,image/gif,image/webp,image/avif,.avif',
    shrink: { maxEdge: 2048, passthroughBytes: 2 * 1024 * 1024, maxBytes: 5 * 1024 * 1024, quality: 0.82 },
});
const page = { imageUpload: shipped() };
vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: page }) }));

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

const small = (type: string, name = 'pic') => new File([new Uint8Array(16)], name, { type });

/** A decoder that answers every file with a 100x100 bitmap, the shape of a picture under the shrink threshold. */
function decodesEverything() {
    vi.stubGlobal('createImageBitmap', async () => ({ width: 100, height: 100, close: () => {} }));
}

beforeEach(() => {
    page.imageUpload = shipped();
});

const bytes = (size: number, type: string, name = 'pic') => new File([new Uint8Array(size)], name, { type });

/** A canvas whose toBlob answers with a tiny blob, recording the type and quality it was asked for. */
function canvasAnswering() {
    const toBlob = vi.fn<(resolve: (blob: Blob | null) => void, type: string, quality?: number) => void>((resolve, type) => resolve(new Blob([new Uint8Array(8)], { type })));
    const canvas = { width: 0, height: 0, getContext: () => ({ drawImage: () => {} }), toBlob };
    const original = document.createElement.bind(document);
    vi.spyOn(document, 'createElement').mockImplementation((tag: string) => (tag === 'canvas' ? (canvas as unknown as HTMLElement) : original(tag)));

    return toBlob;
}

test('a file the browser cannot decode is submitted as picked', async () => {
    // Chrome has no HEIC decoder; the server reads it where its processor does.
    vi.stubGlobal('createImageBitmap', async () => {
        throw new DOMException('unsupported');
    });
    const file = small('image/heic', 'IMG_0001.heic');

    expect(await shrink(file, page.imageUpload)).toBe(file);
});

test('a small picture of an accepted type is submitted as picked, without a canvas', async () => {
    decodesEverything();
    const created = vi.spyOn(document, 'createElement');
    const file = small('image/avif', 'pic.avif');

    expect(await shrink(file, page.imageUpload)).toBe(file);
    expect(created.mock.calls.some(([tag]) => tag === 'canvas')).toBe(false);
});

test('a type outside the accept list, an empty one included, goes through the canvas', async () => {
    // `includes` on the joined string would pass an empty type; the match is whole-entry.
    decodesEverything();
    const created = vi.spyOn(document, 'createElement');

    await shrink(small(''), page.imageUpload);
    await shrink(small('image/webp'), { ...page.imageUpload, accept: 'image/jpeg,image/png' });

    expect(created.mock.calls.filter(([tag]) => tag === 'canvas')).toHaveLength(2);
});

test('with the shrink switched off every file is submitted as picked, a huge one included', async () => {
    vi.stubGlobal('createImageBitmap', async () => ({ width: 6000, height: 4000, close: () => {} }));
    const created = vi.spyOn(document, 'createElement');
    const file = bytes(9 * 1024 * 1024, 'image/jpeg');

    expect(await shrink(file, { ...page.imageUpload, shrink: null })).toBe(file);
    expect(created.mock.calls.some(([tag]) => tag === 'canvas')).toBe(false);
});

test('a large picture is re-encoded as JPEG at the shipped quality, scaled to the shipped edge', async () => {
    vi.stubGlobal('createImageBitmap', async () => ({ width: 4096, height: 2048, close: () => {} }));
    const toBlob = canvasAnswering();

    const out = await shrink(bytes(3 * 1024 * 1024, 'image/jpeg', 'IMG_1.jpeg'), page.imageUpload);

    expect(out.type).toBe('image/jpeg');
    expect(out.name).toBe('IMG_1.jpg');
    expect(toBlob.mock.calls[0]?.[1]).toBe('image/jpeg');
    expect(toBlob.mock.calls[0]?.[2]).toBe(0.82);
});

test('a PNG the canvas would not downscale is submitted as picked up to the upload cap, over the pass-through size included', async () => {
    decodesEverything();
    const created = vi.spyOn(document, 'createElement');

    const underCap = bytes(3 * 1024 * 1024, 'image/png');
    expect(await shrink(underCap, page.imageUpload)).toBe(underCap);
    expect(created.mock.calls.some(([tag]) => tag === 'canvas')).toBe(false);

    // The same bytes as a JPEG are over the pass-through size and go through the canvas.
    canvasAnswering();
    expect(await shrink(bytes(3 * 1024 * 1024, 'image/jpeg'), page.imageUpload)).not.toBe(underCap);
});

test('a PNG over the upload cap still takes the canvas', async () => {
    decodesEverything();
    const toBlob = canvasAnswering();

    await shrink(bytes(6 * 1024 * 1024, 'image/png'), page.imageUpload);

    expect(toBlob).toHaveBeenCalledOnce();
});

test('an animated WebP is submitted as picked, without being decoded', async () => {
    const decode = vi.fn();
    vi.stubGlobal('createImageBitmap', decode);
    // RIFF....WEBPVP8X + flags with the animation bit, padded past the 21st byte.
    const header = new Uint8Array(30);
    header.set([0x52, 0x49, 0x46, 0x46], 0);
    header.set([0x57, 0x45, 0x42, 0x50, 0x56, 0x50, 0x38, 0x58], 8);
    header[20] = 0x02;
    const file = new File([header], 'loop.webp', { type: 'image/webp' });

    expect(await shrink(file, page.imageUpload)).toBe(file);
    expect(decode).not.toHaveBeenCalled();

    // The still flag bits leave the file to the decoder.
    header[20] = 0x10;
    const still = new File([header], 'still.webp', { type: 'image/webp' });
    vi.stubGlobal('createImageBitmap', async () => ({ width: 100, height: 100, close: () => {} }));
    expect(await shrink(still, page.imageUpload)).toBe(still);
});

test('an APNG is submitted as picked, its acTL found behind a chunk of any size', async () => {
    const decode = vi.fn();
    vi.stubGlobal('createImageBitmap', decode);
    const chunk = (type: string, length: number) => {
        const out = new Uint8Array(12 + length);
        new DataView(out.buffer).setUint32(0, length);
        out.set(Array.from(type, (c) => c.charCodeAt(0)), 4);
        return out;
    };
    const signature = new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
    const animated = new File([signature, chunk('IHDR', 13), chunk('iCCP', 70000), chunk('acTL', 8), chunk('IDAT', 4)], 'a.png', { type: 'image/png' });
    const plain = new File([signature, chunk('IHDR', 13), chunk('IDAT', 4), chunk('IEND', 0)], 'p.png', { type: 'image/png' });

    expect(await shrink(animated, page.imageUpload)).toBe(animated);
    expect(decode).not.toHaveBeenCalled();

    vi.stubGlobal('createImageBitmap', async () => ({ width: 100, height: 100, close: () => {} }));
    expect(await shrink(plain, page.imageUpload)).toBe(plain);
});

test('the picker offers what the page was shipped', () => {
    page.imageUpload.accept = 'image/jpeg,image/png';
    const { container } = render(<ImagesField id="images" label="Images" files={[]} onChange={() => {}} errors={{}} />);

    expect(container.querySelector('input[type="file"]')?.getAttribute('accept')).toBe('image/jpeg,image/png');
});

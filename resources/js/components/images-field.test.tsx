import { cleanup, render } from '@testing-library/react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { fakeT } from '@/lib/test-i18n';
import { ImagesField, shrink } from './images-field';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

// The accept list is a shared prop; this is what the server shipped to the page under test.
const page = { imageUpload: { accept: 'image/jpeg,image/png,image/gif,image/webp,image/avif,.avif' } };
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
    page.imageUpload.accept = 'image/jpeg,image/png,image/gif,image/webp,image/avif,.avif';
});

test('a file the browser cannot decode is submitted as picked', async () => {
    // Chrome has no HEIC decoder; the server reads it where its processor does.
    vi.stubGlobal('createImageBitmap', async () => {
        throw new DOMException('unsupported');
    });
    const file = small('image/heic', 'IMG_0001.heic');

    expect(await shrink(file, page.imageUpload.accept)).toBe(file);
});

test('a small picture of an accepted type is submitted as picked, without a canvas', async () => {
    decodesEverything();
    const created = vi.spyOn(document, 'createElement');
    const file = small('image/avif', 'pic.avif');

    expect(await shrink(file, page.imageUpload.accept)).toBe(file);
    expect(created.mock.calls.some(([tag]) => tag === 'canvas')).toBe(false);
});

test('a type outside the accept list, an empty one included, goes through the canvas', async () => {
    // `includes` on the joined string would pass an empty type; the match is whole-entry.
    decodesEverything();
    const created = vi.spyOn(document, 'createElement');

    await shrink(small(''), page.imageUpload.accept);
    await shrink(small('image/webp'), 'image/jpeg,image/png');

    expect(created.mock.calls.filter(([tag]) => tag === 'canvas')).toHaveLength(2);
});

test('the picker offers what the page was shipped', () => {
    page.imageUpload.accept = 'image/jpeg,image/png';
    const { container } = render(<ImagesField id="images" label="Images" files={[]} onChange={() => {}} errors={{}} />);

    expect(container.querySelector('input[type="file"]')?.getAttribute('accept')).toBe('image/jpeg,image/png');
});

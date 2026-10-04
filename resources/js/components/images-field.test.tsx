import { cleanup, fireEvent, render, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { fakeT } from '@/lib/test-i18n';
import { ImagesField, shrink, useShrunkPick } from './images-field';

vi.mock('@/lib/i18n', () => ({ useT: () => fakeT }));

// The upload policy is a shared prop; this is what the server shipped to the page under test.
const shipped = () => ({
    accept: 'image/jpeg,image/png,image/gif,image/webp,image/avif,.avif',
    shrink: { maxEdge: 2048, passthroughBytes: 2 * 1024 * 1024, maxBytes: 5 * 1024 * 1024, quality: 0.82, keepsFrames: true },
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

    return { toBlob, canvas };
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
    vi.stubGlobal('createImageBitmap', async () => ({ width: 4000, height: 2000, close: () => {} }));
    const { toBlob, canvas } = canvasAnswering();
    // Not the values the constants once held, so a re-encode that ignored the prop shows.
    const upload = { ...page.imageUpload, shrink: { ...shipped().shrink, maxEdge: 1000, quality: 0.5 } };

    const out = await shrink(bytes(3 * 1024 * 1024, 'image/jpeg', 'IMG_1.jpeg'), upload);

    expect(out.type).toBe('image/jpeg');
    expect(out.name).toBe('IMG_1.jpg');
    expect([canvas.width, canvas.height]).toEqual([1000, 500]);
    expect(toBlob.mock.calls[0]?.[1]).toBe('image/jpeg');
    expect(toBlob.mock.calls[0]?.[2]).toBe(0.5);
});

test('a PNG the canvas would not downscale is submitted as picked up to the upload cap, over the pass-through size included', async () => {
    decodesEverything();
    const created = vi.spyOn(document, 'createElement');

    const underCap = bytes(3 * 1024 * 1024, 'image/png');
    expect(await shrink(underCap, page.imageUpload)).toBe(underCap);
    expect(created.mock.calls.some(([tag]) => tag === 'canvas')).toBe(false);

    // The same bytes as a JPEG are over the pass-through size and go through the canvas.
    const { toBlob } = canvasAnswering();
    const jpeg = bytes(3 * 1024 * 1024, 'image/jpeg');
    expect(await shrink(jpeg, page.imageUpload)).not.toBe(jpeg);
    expect(toBlob).toHaveBeenCalledOnce();
});

test('a PNG over the upload cap still takes the canvas', async () => {
    decodesEverything();
    const { toBlob } = canvasAnswering();

    await shrink(bytes(6 * 1024 * 1024, 'image/png'), page.imageUpload);

    expect(toBlob).toHaveBeenCalledOnce();
});

const webp = (flags: number, name: string) => {
    // RIFF....WEBPVP8X + the flags byte, padded past the 21st byte.
    const header = new Uint8Array(30);
    header.set([0x52, 0x49, 0x46, 0x46], 0);
    header.set([0x57, 0x45, 0x42, 0x50, 0x56, 0x50, 0x38, 0x58], 8);
    header[20] = flags;

    return new File([header], name, { type: 'image/webp' });
};

test('where the server keeps frames, an animated WebP is submitted as picked without being decoded, alpha or not', async () => {
    const decode = vi.fn();
    vi.stubGlobal('createImageBitmap', decode);

    for (const file of [webp(0x02, 'loop.webp'), webp(0x12, 'loop-alpha.webp')]) {
        expect(await shrink(file, page.imageUpload)).toBe(file);
    }
    expect(decode).not.toHaveBeenCalled();

    // The still flag bits leave the file to the decoder.
    const still = webp(0x10, 'still.webp');
    vi.stubGlobal('createImageBitmap', async () => ({ width: 100, height: 100, close: () => {} }));
    expect(await shrink(still, page.imageUpload)).toBe(still);
});

test('where the server would flatten it anyway, an animated WebP is decoded and shrunk like a still', async () => {
    vi.stubGlobal('createImageBitmap', async () => ({ width: 4000, height: 4000, close: () => {} }));
    const { toBlob } = canvasAnswering();
    const upload = { ...page.imageUpload, shrink: { ...shipped().shrink, keepsFrames: false } };

    const out = await shrink(webp(0x12, 'loop-alpha.webp'), upload);

    expect(toBlob).toHaveBeenCalledOnce();
    expect(out.type).toBe('image/jpeg');
});

test('the picker offers what the page was shipped', () => {
    page.imageUpload.accept = 'image/jpeg,image/png';
    const { container } = render(<ImagesField id="images" label="Images" files={[]} onChange={() => {}} errors={{}} />);

    expect(container.querySelector('input[type="file"]')?.getAttribute('accept')).toBe('image/jpeg,image/png');
});

function SinglePicker({ slow }: { slow: Promise<void> }) {
    const [file, setFile] = useState<File | null>(null);
    const picked = useShrunkPick(file, setFile);
    vi.stubGlobal('createImageBitmap', async () => {
        await slow;

        return { width: 4000, height: 4000, close: () => {} };
    });

    return (
        <form onReset={() => setFile(null)}>
            <input aria-label="Picture" type="file" accept={picked.accept} onChange={picked.pick} />
            <output data-testid="value">{file?.name ?? 'none'}</output>
            {picked.busy && <p>Processing</p>}
        </form>
    );
}

test('a single-file pick is replaced by its shrunk file, unless the form was reset while the shrink ran', async () => {
    canvasAnswering();
    let finish!: () => void;
    const slow = new Promise<void>((resolve) => {
        finish = resolve;
    });
    const { getByLabelText, getByTestId, queryByText } = render(<SinglePicker slow={slow} />);
    const raw = new File([new Uint8Array(3 * 1024 * 1024)], 'IMG_7.heic', { type: 'image/heic' });

    fireEvent.change(getByLabelText('Picture'), { target: { files: [raw] } });
    expect(getByTestId('value').textContent).toBe('IMG_7.heic');
    expect(queryByText('Processing')).not.toBeNull();

    finish();
    await waitFor(() => expect(getByTestId('value').textContent).toBe('IMG_7.jpg'));
    expect(queryByText('Processing')).toBeNull();
});

test('a shrink that outlives a reset leaves the emptied form alone', async () => {
    canvasAnswering();
    let finish!: () => void;
    const slow = new Promise<void>((resolve) => {
        finish = resolve;
    });
    const { getByLabelText, getByTestId, container } = render(<SinglePicker slow={slow} />);
    const raw = new File([new Uint8Array(3 * 1024 * 1024)], 'IMG_7.heic', { type: 'image/heic' });

    fireEvent.change(getByLabelText('Picture'), { target: { files: [raw] } });
    fireEvent.reset(container.querySelector('form')!);
    expect(getByTestId('value').textContent).toBe('none');

    finish();
    await slow;
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(getByTestId('value').textContent).toBe('none');
});

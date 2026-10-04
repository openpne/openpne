import { expect, test } from 'vitest';
import { assignToInput, carriesFiles, droppedImages, pastedImages } from './file-drop';

const picture = (name: string) => new File([new Uint8Array(4)], name, { type: 'image/png' });

function transfer(files: File[], text = ''): DataTransfer {
    const data = new DataTransfer();
    for (const file of files) {
        data.items.add(file);
    }
    if (text !== '') {
        data.setData('text/plain', text);
    }

    return data;
}

test('a drag carries files only when the browser says so', () => {
    // Plain shapes: the browser lists `Files` among the types of a file drag, which the test DOM's
    // DataTransfer does not reproduce.
    expect(carriesFiles({ types: ['Files'] } as unknown as DataTransfer)).toBe(true);
    expect(carriesFiles({ types: ['text/plain'] } as unknown as DataTransfer)).toBe(false);
    expect(carriesFiles(null)).toBe(false);
});

test('only the pictures among dropped files are handed on, and none from a picture dragged off a page', () => {
    const files = droppedImages(transfer([picture('a.png'), new File(['x'], 'notes.txt', { type: 'text/plain' })]));
    expect(files.map((file) => file.name)).toEqual(['a.png']);

    const offAPage = { types: ['text/uri-list', 'text/html', 'Files'], files: [picture('hero.png')] } as unknown as DataTransfer;
    expect(droppedImages(offAPage)).toEqual([]);
});

test('a paste that carries plain text pastes the text and no picture', () => {
    expect(pastedImages(transfer([picture('cell.png')], 'A1\tB1'))).toEqual([]);
    expect(pastedImages(transfer([picture('shot.png')])).map((file) => file.name)).toEqual(['shot.png']);
    expect(pastedImages(null)).toEqual([]);
});

test('a drop lands in the input as a pick, the first file alone for a single-file input', () => {
    const input = document.createElement('input');
    input.type = 'file';
    const changes: number[] = [];
    input.addEventListener('change', () => changes.push(input.files?.length ?? 0));

    assignToInput(input, [picture('a.png'), picture('b.png')]);
    expect(changes).toEqual([1]);
    expect(input.files?.[0]?.name).toBe('a.png');

    input.multiple = true;
    assignToInput(input, [picture('a.png'), picture('b.png')]);
    expect(changes).toEqual([1, 2]);
});

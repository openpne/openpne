import assert from 'node:assert/strict';
import { test } from 'node:test';
import { acceptPicks, MAX_POST_IMAGES, sizeLabel, withinAttachmentCap } from './image-picks.ts';

test('a selection that fits is appended in pick order', () => {
    assert.deepEqual(acceptPicks(['a'], ['b', 'c']), { files: ['a', 'b', 'c'], refused: false });
});

test('the cap counts what is already held, not the pick alone', () => {
    assert.deepEqual(acceptPicks(['a', 'b'], ['c', 'd']), { files: ['a', 'b', 'c'], refused: true });
});

test('a full selection accepts nothing and says so', () => {
    assert.deepEqual(acceptPicks(['a', 'b', 'c'], ['d']), { files: ['a', 'b', 'c'], refused: true });
});

test('exactly filling the cap is not a refusal', () => {
    assert.deepEqual(acceptPicks([], ['a', 'b', 'c']), { files: ['a', 'b', 'c'], refused: false });
});

test('the default cap is the server contract', () => {
    assert.equal(MAX_POST_IMAGES, 3);
    assert.equal(acceptPicks<string>([], ['a', 'b', 'c', 'd']).files.length, MAX_POST_IMAGES);
});

test('the cap exempts what the server reads as a picture, and nothing typeless or outside that list', () => {
    const upload = { accept: 'image/jpeg,image/png', attachmentMaxBytes: 100 };
    const file = (name: string, type: string, size: number) => new File([new Uint8Array(size)], name, { type });
    const picked = [file('a.png', 'image/png', 500), file('b.pdf', 'application/pdf', 500), file('c.xdw', '', 500), file('d.psd', 'image/vnd.adobe.photoshop', 500), file('e.pdf', 'application/pdf', 50)];

    const { files, refused } = withinAttachmentCap(picked, upload);

    assert.deepEqual(files.map((f) => f.name), ['a.png', 'e.pdf']);
    assert.equal(refused, true);
    assert.equal(withinAttachmentCap([file('f.pdf', 'application/pdf', 100)], upload).refused, false);
});

test('the cap label reads as kilobytes under a megabyte and keeps a fraction above', () => {
    assert.equal(sizeLabel(10 * 1024 * 1024), '10 MB');
    assert.equal(sizeLabel(1536 * 1024), '1.5 MB');
    assert.equal(sizeLabel(512 * 1024), '512 KB');
});

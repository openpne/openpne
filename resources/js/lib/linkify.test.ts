import assert from 'node:assert/strict';
import { test } from 'node:test';
import { linkify } from './linkify.ts';

// Mirrors tests/Unit/Support/BodyTextTest.php for the shared linkify rules. Escaping is not tested
// here — <UserText> defers it to React — so these assert the raw segment shape only.

test('http url becomes a url segment with the full href', () => {
    const segments = linkify('see https://example.com/x here');
    assert.deepEqual(segments, [
        { type: 'text', value: 'see ' },
        { type: 'url', href: 'https://example.com/x', visible: 'https://example.com/x' },
        { type: 'text', value: ' here' },
    ]);
});

test('www url gets an http scheme in the href, visible text unchanged', () => {
    const [, url] = linkify('go to www.example.com now');
    assert.deepEqual(url, { type: 'url', href: 'http://www.example.com', visible: 'www.example.com' });
});

test('trailing punctuation stays outside the url', () => {
    const url = linkify('visit https://example.com.').find((s) => s.type === 'url');
    assert.equal(url && url.type === 'url' && url.href, 'https://example.com');
});

test('multiple urls are each linked', () => {
    const urls = linkify('a https://a.example b http://b.example c').filter((s) => s.type === 'url');
    assert.equal(urls.length, 2);
});

test('non-http schemes are not linked', () => {
    assert.deepEqual(linkify('javascript:alert(1)'), [{ type: 'text', value: 'javascript:alert(1)' }]);
});

test('long url visible text is truncated but the href stays full', () => {
    const url = `https://example.com/${'a'.repeat(80)}`;
    const seg = linkify(url).find((s) => s.type === 'url');
    assert.ok(seg);
    assert.equal(seg.type === 'url' && seg.href, url);
    assert.ok(seg.type === 'url' && seg.visible.endsWith('...'));
    assert.equal(seg.type === 'url' && seg.visible.length, 57 + 3);
});

test('null and undefined render an empty text segment', () => {
    assert.deepEqual(linkify(null), [{ type: 'text', value: '' }]);
    assert.deepEqual(linkify(undefined), [{ type: 'text', value: '' }]);
});

test('full-width url truncation is char-based (intentional divergence from PHP mb_strwidth)', () => {
    // 47 code units (< 57), so no truncation here. BodyText's Str::limit measures display width
    // (mb_strwidth ~= 87) and WOULD append an ellipsis — a documented, intentional divergence; the
    // PHP side pins its own behavior in BodyTextTest.
    const url = `http://${'あ'.repeat(40)}`;
    const seg = linkify(url).find((s) => s.type === 'url');
    assert.ok(seg);
    assert.equal(seg.type === 'url' && seg.visible, url);
    assert.ok(seg.type === 'url' && !seg.visible.endsWith('...'));
});

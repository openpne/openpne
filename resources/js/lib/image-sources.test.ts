import assert from 'node:assert/strict';
import { test } from 'node:test';
import { cropSrcSet, fitFallbackUrl, fitSrcSet } from './image-sources.ts';

const ladder = [
    { url: '/a320', box: 320 },
    { url: '/a640', box: 640 },
    { url: '/a1200', box: 1200 },
];

test('a fit candidate states the width the box actually scales the picture to', () => {
    assert.equal(fitSrcSet(ladder, 4032, 3024), '/a320 320w, /a640 640w, /a1200 1200w');
});

test('a portrait fits by its long side, so every candidate is narrower than its box', () => {
    assert.equal(fitSrcSet(ladder, 3024, 4032), '/a320 240w, /a640 480w, /a1200 900w');
});

test('a source smaller than every box collapses to one candidate', () => {
    assert.equal(fitSrcSet(ladder, 200, 150), '/a320 200w');
});

test('the rungs that clear the source are folded into the first of them', () => {
    assert.equal(fitSrcSet(ladder, 500, 500), '/a320 320w, /a640 500w');
});

test('an unknown size yields no srcset rather than a derived one', () => {
    assert.equal(fitSrcSet(ladder, null, 3024), null);
    assert.equal(fitSrcSet(ladder, 4032, null), null);
    assert.equal(fitSrcSet(ladder, 4032, 0), null);
});

test('a missing file has no candidates at all', () => {
    assert.equal(fitSrcSet([], 4032, 3024), null);
    assert.equal(fitFallbackUrl([]), null);
});

test('the fallback is the middle rung', () => {
    assert.equal(fitFallbackUrl(ladder), '/a640');
});

test('a crop descriptor is the width in the url', () => {
    assert.equal(
        cropSrcSet([
            { url: '/c300', width: 300 },
            { url: '/c600', width: 600 },
        ]),
        '/c300 300w, /c600 600w',
    );
});

test('a ratio the server did not ship has no srcset', () => {
    assert.equal(cropSrcSet(undefined), null);
    assert.equal(cropSrcSet([]), null);
});

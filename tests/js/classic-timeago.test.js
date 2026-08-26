import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInThisContext } from 'node:vm';

/**
 * OpenPNE 3's jquery.timeago ladder, rung by rung, on the words the page hands the script.
 *
 * The script is loaded the way the browser loads it (evaluated, not imported) with a `module` in
 * scope: that branch hands back the pure half and skips the DOM wiring.
 */
const load = (path) => {
    const source = readFileSync(fileURLToPath(new URL(`../../${path}`, import.meta.url)), 'utf8');
    const script = { exports: {} };
    runInThisContext(`(function (module) {\n${source}\n})`, { filename: path })(script);

    return script.exports;
};

const { relativeLabel } = load('public/js/classic-timeago.js');

const STRINGS = {
    minute: '1分前', minutes: ':count分前', hour: '1時間前', hours: ':count時間前',
    day: '1日前', days: ':count日前', month: '1か月前', months: ':countか月前',
    year: '1年前', years: ':count年前',
};
const s = 1000;
const m = 60 * s;
const h = 60 * m;
const d = 24 * h;
const label = (ms) => relativeLabel(ms, STRINGS);

test('the rungs are the ones jquery.timeago used, rounded as it rounded', () => {
    assert.equal(label(0), '1分前');
    assert.equal(label(44 * s), '1分前');
    assert.equal(label(89 * s), '1分前');
    assert.equal(label(90 * s), '2分前');
    assert.equal(label(44 * m), '44分前');
    assert.equal(label(45 * m), '1時間前');
    assert.equal(label(89 * m), '1時間前');
    assert.equal(label(90 * m), '2時間前');
    assert.equal(label(23 * h), '23時間前');
    assert.equal(label(24 * h), '1日前');
    assert.equal(label(41 * h), '1日前');
    assert.equal(label(42 * h), '2日前');
    assert.equal(label(29 * d), '29日前');
    assert.equal(label(30 * d), '1か月前');
    assert.equal(label(44 * d), '1か月前');
    assert.equal(label(45 * d), '2か月前');
    assert.equal(label(364 * d), '12か月前');
    assert.equal(label(365 * d), '1年前');
    assert.equal(label(1.49 * 365 * d), '1年前');
    assert.equal(label(1.5 * 365 * d), '2年前');
    assert.equal(label(10 * 365 * d), '10年前');
});

test('a future date reads by its distance, as allowFuture=false read it', () => {
    assert.equal(label(-3 * h), '3時間前');
});

test('a rung with no words leaves the absolute text alone', () => {
    const missing = { ...STRINGS };
    delete missing.month;
    assert.equal(relativeLabel(30 * d, missing), null);
    assert.equal(relativeLabel(29 * d, missing), '29日前');
    assert.equal(relativeLabel(30 * d, { ...STRINGS, month: '' }), null);
});

test('a distance that is not a number leaves the absolute text alone', () => {
    assert.equal(label(NaN), null);
    assert.equal(label(undefined), null);
    assert.equal(label(Infinity), null);
});

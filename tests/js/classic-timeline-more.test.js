import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInThisContext } from 'node:vm';

/**
 * The inline reply layer's decisions: what counts as 140 characters, when the button may fire, and
 * which of a refusal's words are safe to show a member.
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

const { nextUrl } = load('public/js/classic-timeline-more.js');

const BASE = 'https://sns.example/timeline';

test('the next page is the rel="next" link on the page origin', () => {
    assert.equal(nextUrl('</timeline/rows?page=3>; rel="next"', BASE), 'https://sns.example/timeline/rows?page=3');
    assert.equal(nextUrl('<https://sns.example/timeline/rows?page=3&per_page=5>; rel="next"', BASE), 'https://sns.example/timeline/rows?page=3&per_page=5');
    assert.equal(nextUrl('</a>; rel="prev", </timeline/rows?page=2>; rel="next"', BASE), 'https://sns.example/timeline/rows?page=2');
});

test('no next, another relation or another origin is nothing to follow', () => {
    assert.equal(nextUrl(null, BASE), null);
    assert.equal(nextUrl('', BASE), null);
    assert.equal(nextUrl('</timeline/rows?page=1>; rel="prev"', BASE), null);
    assert.equal(nextUrl('<https://evil.example/timeline/rows?page=3>; rel="next"', BASE), null);
    assert.equal(nextUrl('<http://sns.example/timeline/rows?page=3>; rel="next"', BASE), null);
    assert.equal(nextUrl('<https://>; rel="next"', BASE), null);
});

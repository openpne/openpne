import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { cn } from './utils.ts';

test('the later of two project radii or inset paddings wins, as it does for built-in classes', () => {
    assert.equal(cn('rounded-field', 'rounded-full'), 'rounded-full');
    assert.equal(cn('rounded-full', 'rounded-card'), 'rounded-card');
    assert.equal(cn('rounded-field', 'rounded-tile'), 'rounded-tile');
    assert.equal(cn('pt-safe-4', 'pt-4'), 'pt-4');
    assert.equal(cn('pt-4', 'pt-safe-4'), 'pt-safe-4');
    assert.equal(cn('pt-safe-4', 'pt-safe-1'), 'pt-safe-1');
    assert.equal(cn('pb-safe', 'pb-offset-2'), 'pb-offset-2');
    assert.equal(cn('top-safe-1', 'top-3'), 'top-3');
    assert.equal(cn('right-safe-2', 'right-3'), 'right-3');
});

test('an inset utility on one edge leaves the other edges alone', () => {
    assert.equal(cn('pt-safe-4', 'pb-4'), 'pt-safe-4 pb-4');
    assert.equal(cn('pl-safe', 'pr-safe'), 'pl-safe pr-safe');
});

// app.css and utils.ts are kept in step by hand: a radius token or edge utility added to one without
// the other would merge as two classes with the stylesheet's order deciding.
test('every radius token and edge utility app.css declares merges against its built-in class', () => {
    const css = readFileSync(new URL('../../css/app.css', import.meta.url), 'utf8');
    const radii = [...css.matchAll(/^\s*--radius-([a-z]+):/gm)].map((m) => m[1] ?? '');
    const edges = [...new Set([...css.matchAll(/^@utility ([a-z]+)-(?:safe|offset)/gm)].map((m) => m[1] ?? ''))];
    assert.ok(radii.length >= 3 && edges.length >= 8, `${radii.length} radii, ${edges.length} edges`);

    for (const name of radii) {
        assert.equal(cn(`rounded-${name}`, 'rounded-full'), 'rounded-full', name);
    }
    for (const edge of edges) {
        assert.equal(cn(`${edge}-safe-4`, `${edge}-4`), `${edge}-4`, edge);
        assert.equal(cn(`${edge}-offset-4`, `${edge}-4`), `${edge}-4`, edge);
    }
});

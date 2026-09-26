import assert from 'node:assert/strict';
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

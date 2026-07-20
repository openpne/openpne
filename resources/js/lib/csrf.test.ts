import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readCookie } from './csrf.ts';

test('reads a cookie value by name', () => {
    assert.equal(readCookie('XSRF-TOKEN=abc123; other=x', 'XSRF-TOKEN'), 'abc123');
});

test('tolerates leading spaces after the semicolon separator', () => {
    assert.equal(readCookie('a=1; XSRF-TOKEN=tok; b=2', 'XSRF-TOKEN'), 'tok');
});

test('returns null when the cookie is absent', () => {
    assert.equal(readCookie('a=1; b=2', 'XSRF-TOKEN'), null);
});

test('returns the raw (still URL-encoded) value; decoding is the caller’s job', () => {
    assert.equal(readCookie('XSRF-TOKEN=a%3Db', 'XSRF-TOKEN'), 'a%3Db');
});

test('a value containing = keeps everything after the first =', () => {
    assert.equal(readCookie('XSRF-TOKEN=a=b=c', 'XSRF-TOKEN'), 'a=b=c');
});

test('empty cookie string yields null', () => {
    assert.equal(readCookie('', 'XSRF-TOKEN'), null);
});

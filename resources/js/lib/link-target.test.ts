import assert from 'node:assert/strict';
import { test } from 'node:test';
import { inAppHref, isPlainClick } from './link-target.ts';

// Mirrors tests/Unit/Support/LinkTargetTest.php for the shared host rule.

test('a url on this host becomes the path the router visits, query and hash kept', () => {
    assert.equal(inAppHref('https://sns.example.test/diary/1?page=2#c3', 'sns.example.test'), '/diary/1?page=2#c3');
});

test('the scheme does not decide: http and https of this host are the same site', () => {
    assert.equal(inAppHref('http://sns.example.test/diary/1', 'sns.example.test'), '/diary/1');
});

test('host case does not decide', () => {
    assert.equal(inAppHref('https://SNS.Example.test/x', 'sns.example.test'), '/x');
});

test('another host leaves this site', () => {
    assert.equal(inAppHref('https://example.org/x', 'sns.example.test'), null);
    assert.equal(inAppHref('https://sns.example.test.evil.example/x', 'sns.example.test'), null);
});

test('a port decides, as it does for a card', () => {
    assert.equal(inAppHref('https://sns.example.test:8443/x', 'sns.example.test'), null);
    assert.equal(inAppHref('http://localhost:8080/x', 'localhost:8080'), '/x');
});

test('a non-http scheme or a relative reference is not one of ours', () => {
    assert.equal(inAppHref('javascript:alert(1)', 'sns.example.test'), null);
    assert.equal(inAppHref('/diary/1', 'sns.example.test'), null);
});

test('a modified or secondary-button click is left to the browser', () => {
    const plain = { defaultPrevented: false, button: 0, metaKey: false, ctrlKey: false, shiftKey: false, altKey: false };

    assert.equal(isPlainClick(plain), true);
    for (const key of ['metaKey', 'ctrlKey', 'shiftKey', 'altKey', 'defaultPrevented'] as const) {
        assert.equal(isPlainClick({ ...plain, [key]: true }), false, key);
    }
    assert.equal(isPlainClick({ ...plain, button: 1 }), false);
});

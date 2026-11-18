import assert from 'node:assert/strict';
import { test } from 'node:test';
import { inAppHref, isBytesHref, isPlainClick } from './link-target.ts';

// Mirrors tests/Unit/Support/LinkTargetTest.php for the host rule; the server's refusals are its own.

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

test('a path that begins with a doubled slash is kept as written, behind a dot segment', () => {
    assert.equal(inAppHref('https://sns.example.test//evil.example/x', 'sns.example.test'), '/.//evil.example/x');
    assert.equal(inAppHref('https://sns.example.test/\\evil.example/x', 'sns.example.test'), '/.//evil.example/x');
    assert.equal(inAppHref('https://sns.example.test///evil.example/x', 'sns.example.test'), '/.///evil.example/x');
    assert.equal(inAppHref('https://sns.example.test//?a=1#b', 'sns.example.test'), '/.//?a=1#b');
    assert.equal(inAppHref('https://sns.example.test/x//evil.example/y', 'sns.example.test'), '/x//evil.example/y');
});

test('whatever the spelling, the path of a link to this host lands on exactly that url of this host', () => {
    const spellings = [
        'https://sns.example.test////////evil.example/x',
        'https://sns.example.test\\/evil.example/x',
        'https://sns.example.test/\\/evil.example/x',
        'https:sns.example.test//evil.example/x',
        'https:\\\\sns.example.test//evil.example/x',
        ' \thttps://sns.example.test//evil.example/x',
        'https://sns.example.test/\t/evil.example/x',
        'https://sns.example.test/\r\n/evil.example/x',
        'https://sns.example.test/%2F%2Fevil.example/x',
        'https://sns.example.test/%5C%5Cevil.example/x',
        'https://sns.example.test//@evil.example/x',
        'https://evil.example@sns.example.test//evil.example/x',
        'https://sns.example.test/a/..//evil.example/x',
        'https://sns.example.test/%2e%2e//evil.example/x',
        'https://sns.example.test//?next=//evil.example',
        'https://sns.example.test/sns//evil.example/x',
    ];
    // Resolved as the browser resolves an href, from the root and from a page of a sub-directory install.
    for (const href of spellings) {
        const path = inAppHref(href, 'sns.example.test');
        assert.notEqual(path, null, href);
        const written = new URL(href);
        for (const page of ['https://sns.example.test/', 'http://sns.example.test/sns/diary/1']) {
            const landed = new URL(path!, page);
            assert.equal(landed.host, 'sns.example.test', `${href} from ${page}`);
            assert.equal(`${landed.pathname}${landed.search}${landed.hash}`, `${written.pathname}${written.search}${written.hash}`, `${href} from ${page}`);
        }
    }
});

test('a non-http scheme or a relative reference is not one of ours', () => {
    assert.equal(inAppHref('javascript:alert(1)', 'sns.example.test'), null);
    assert.equal(inAppHref('/diary/1', 'sns.example.test'), null);
});

test('an OpenPNE 3 upload link is bytes, whatever its extension', () => {
    assert.equal(isBytesHref('/f/show/12.pdf'), true);
    assert.equal(isBytesHref('/f/show/12.backup_2023'), true);
    assert.equal(isBytesHref('/file/abc'), true);
    assert.equal(isBytesHref('/friend/list'), false);
});

test('a modified or secondary-button click is left to the browser', () => {
    const plain = { defaultPrevented: false, button: 0, metaKey: false, ctrlKey: false, shiftKey: false, altKey: false };

    assert.equal(isPlainClick(plain), true);
    for (const key of ['metaKey', 'ctrlKey', 'shiftKey', 'altKey', 'defaultPrevented'] as const) {
        assert.equal(isPlainClick({ ...plain, [key]: true }), false, key);
    }
    assert.equal(isPlainClick({ ...plain, button: 1 }), false);
});

test('a host is read as the browser reads it: full-width letters and an IDN label in punycode', () => {
    assert.equal(inAppHref('https://ｓｎｓ.example.test/x', 'sns.example.test'), '/x');
    assert.equal(inAppHref('https://例え.jp/diary/1', 'xn--r8jz45g.jp'), '/diary/1');
});

test('what the server refuses to read is read here as the browser will land: on this host', () => {
    // The client parses with the browser's own parser, so the host cannot diverge from the one a click
    // reaches; the server (LinkTarget) refuses these because parse_url cannot promise the same.
    assert.equal(inAppHref('https://sns.example.test\\evil.example/', 'sns.example.test'), '/evil.example/');
    assert.equal(inAppHref('https://user:pw@sns.example.test/x', 'sns.example.test'), '/x');
    assert.equal(inAppHref('https://evil.example\\@sns.example.test/', 'sns.example.test'), null);
});

test('a path under a segment that serves bytes is not a page, judged by its first segment alone', () => {
    for (const path of ['/file/abc.pdf', '/file/public/x.png', '/cache/img/jpg/w120_h120/abc.jpg', '/linkCard/diary/1/img/jpg/w_h/x.jpg', '/banner/image/x.png', '/app-icon/t/192.png']) {
        assert.equal(isBytesHref(path), true, path);
    }
    assert.equal(isBytesHref('/file?x=1'), true);
    // A front server that merges slashes serves `//file/x` as `/file/x`.
    assert.equal(isBytesHref('//file/x'), true);
    assert.equal(isBytesHref('/.//file/x'), true);
    assert.equal(isBytesHref('/.///cache/x'), true);
    for (const path of ['/diary/1', '/files/1', '/member/1/file', '/', '/cache-list', '', '/.//', '/.//diary/1', 'file/x']) {
        assert.equal(isBytesHref(path), false, path);
    }
});

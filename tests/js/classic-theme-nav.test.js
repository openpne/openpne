import '../../resources/js/components/compose/test-dom.ts';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInThisContext } from 'node:vm';

const load = (path) => {
    const source = readFileSync(fileURLToPath(new URL(`../../${path}`, import.meta.url)), 'utf8');
    const script = { exports: {} };
    runInThisContext(`(function (module) {\n${source}\n})`, { filename: path })(script);

    return script.exports;
};

const { setOpen, plainClick } = load('public/js/classic-theme-nav.js');

test('opening marks the item and tells assistive technology through the toggle', () => {
    document.body.innerHTML = '<div id="globalNav"><ul class="nav pull-right"><li id="globalNav" class="dropdown"><a href="#" class="dropdown-toggle" aria-expanded="false"><p>menu</p></a><ul class="dropdown-menu"><li><a href="/">Home</a></li></ul></li></ul></div>';
    const item = document.querySelector('li.dropdown');
    const toggle = item.querySelector('.dropdown-toggle');

    setOpen(item, true);
    assert.equal(item.classList.contains('open'), true);
    assert.equal(toggle.getAttribute('aria-expanded'), 'true');

    setOpen(item, false);
    assert.equal(item.classList.contains('open'), false);
    assert.equal(toggle.getAttribute('aria-expanded'), 'false');
});

test('a modified or secondary-button click is left to the browser', () => {
    const plain = { metaKey: false, ctrlKey: false, shiftKey: false, altKey: false, button: 0 };

    assert.equal(plainClick(plain), true);
    for (const key of ['metaKey', 'ctrlKey', 'shiftKey', 'altKey']) {
        assert.equal(plainClick({ ...plain, [key]: true }), false, key);
    }
    assert.equal(plainClick({ ...plain, button: 1 }), false);
});

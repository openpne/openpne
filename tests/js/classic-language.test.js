import '../../resources/js/components/compose/test-dom.ts';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInThisContext } from 'node:vm';

runInThisContext(readFileSync(fileURLToPath(new URL('../../public/js/classic-language.js', import.meta.url)), 'utf8'));

const choose = async (status) => {
    document.body.innerHTML = '<form data-language-switch method="post" action="/locale"><input type="hidden" name="_token" value="t"><select name="locale"><option value="ja">ja</option><option value="en">en</option></select></form>';
    const calls = { fetch: [], reload: 0, submit: 0 };
    globalThis.fetch = async (url, init) => {
        calls.fetch.push({ url, init });

        return { ok: status < 400 };
    };
    const { reload } = window.location;
    window.location.reload = () => calls.reload++;
    document.querySelector('form').submit = () => calls.submit++;

    const select = document.querySelector('select');
    select.value = 'en';
    select.dispatchEvent(new Event('change', { bubbles: true }));
    await new Promise((resolve) => setTimeout(resolve, 0));
    window.location.reload = reload;

    return calls;
};

// The page reloads itself: a secret-bearing page is never the session's previous URL to go back to.
test('a choice is posted for JSON and the page reloads itself', async () => {
    const calls = await choose(204);

    assert.equal(calls.reload, 1);
    assert.equal(calls.submit, 0);
    assert.equal(calls.fetch.length, 1);
    assert.equal(calls.fetch[0].init.headers.Accept, 'application/json');
    assert.equal(calls.fetch[0].init.body.get('locale'), 'en');
    assert.equal(calls.fetch[0].init.body.get('_token'), 't');
});

test('a refused post falls back to submitting the form', async () => {
    const calls = await choose(419);

    assert.equal(calls.reload, 0);
    assert.equal(calls.submit, 1);
});

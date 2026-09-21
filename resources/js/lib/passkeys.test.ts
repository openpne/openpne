import assert from 'node:assert/strict';
import { test } from 'node:test';
import { NotSupportedError, PasskeyError, PasskeyExistsError, UserCancelledError } from '@laravel/passkeys';
import { passkeyErrorKey } from './passkeys.ts';

test('ceremony errors map to their own keys', () => {
    assert.equal(passkeyErrorKey(new UserCancelledError()), 'The passkey prompt was cancelled.');
    assert.equal(passkeyErrorKey(new PasskeyExistsError()), 'This device already holds a passkey for your account.');
    assert.equal(passkeyErrorKey(new NotSupportedError()), 'This browser does not support passkeys.');
});

test("the framework's throttle literal maps to the translatable key", () => {
    assert.equal(passkeyErrorKey(new Error('Too Many Attempts.')), 'Too many attempts. Please wait a moment and try again.');
});

test('a server message passes through untouched, however the client wrapped it', () => {
    assert.equal(passkeyErrorKey(new Error('本人確認から時間が経ちました。')), '本人確認から時間が経ちました。');
    assert.equal(passkeyErrorKey(new PasskeyError('本人確認から時間が経ちました。')), '本人確認から時間が経ちました。');
});

test('an empty or unknown error falls back to the generic key', () => {
    assert.equal(passkeyErrorKey(new Error('')), 'The passkey could not be used. Please try again.');
    assert.equal(passkeyErrorKey(new PasskeyError('An unknown error occurred.')), 'The passkey could not be used. Please try again.');
    assert.equal(passkeyErrorKey('nope'), 'The passkey could not be used. Please try again.');
    assert.equal(passkeyErrorKey(new Error("Unexpected token '<', \"<!DOCTYPE \"... is not valid JSON")), 'The passkey could not be used. Please try again.');
});

test('what a browser says about a failed fetch is not a message for the member', () => {
    // The client forwards the TypeError's text the same way it forwards a server message.
    for (const text of ['Failed to fetch', 'Load failed', 'NetworkError when attempting to fetch resource.']) {
        assert.equal(passkeyErrorKey(new Error(text)), 'The passkey could not be used. Please try again.');
    }
});

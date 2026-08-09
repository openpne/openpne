import assert from 'node:assert/strict';
import { test } from 'node:test';
import { urlBase64ToUint8Array } from './push.ts';

// A real base64url VAPID public key: an uncompressed P-256 point, so 65 bytes leading with 0x04. This
// exact string carries both base64url-only characters (`-` and `_`) and needs one `=` of padding.
const VAPID = 'BO5SA4Xiyb-wPdT3EWpWvOGRjrvEucN9EtkwksRsS_nlZMAdk6YS5eMi4ITeOzfWHAIcwhjyC2kf4B9AS9UlFa8';

test('a VAPID public key decodes to a 65-byte uncompressed point', () => {
    const bytes = urlBase64ToUint8Array(VAPID);
    assert.equal(bytes.length, 65);
    assert.equal(bytes[0], 0x04);
});

test('base64url characters map to their base64 equivalents', () => {
    // `-` -> `+` and `_` -> `/`; decoding the two forms must land on the same bytes.
    const canonical = VAPID.replace(/-/g, '+').replace(/_/g, '/');
    assert.deepEqual(urlBase64ToUint8Array(VAPID), urlBase64ToUint8Array(canonical));
});

test('padding is inferred, so a padding-less string decodes', () => {
    // "Ma" is 2 base64 chars (one byte) and would need "==" to be canonical base64.
    assert.deepEqual([...urlBase64ToUint8Array('Ma')], [...urlBase64ToUint8Array('Ma==')]);
    assert.equal(urlBase64ToUint8Array('Ma').length, 1);
});

import assert from 'node:assert/strict';
import { test } from 'node:test';
import { reconcileOutcome, shouldReconcile, urlBase64ToUint8Array } from './push.ts';

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

// shouldReconcile gates the store POST: reconcile only on an ownership transition, never on a
// confirmed same-member binding — that skip is what keeps ordinary browsing off the store route's
// throttle:30,1 (a per-load POST would 429 and, failing closed, unsubscribe the member's own device).
const TTL = 12 * 60 * 60 * 1000;
const NOW = 1_700_000_000_000;
const MARKER = { endpoint: 'https://push.example/abc', memberId: 7, at: NOW };

test('skip: a confirmed same-member binding within the TTL does not POST', () => {
    assert.equal(shouldReconcile(MARKER, MARKER.endpoint, MARKER.memberId, NOW + 1_000, TTL), false);
});

test('POST: no marker (first reconcile on this device)', () => {
    assert.equal(shouldReconcile(null, MARKER.endpoint, MARKER.memberId, NOW, TTL), true);
});

test('POST: the member differs (account switch reclaims the row)', () => {
    assert.equal(shouldReconcile(MARKER, MARKER.endpoint, 8, NOW, TTL), true);
});

test('POST: the endpoint differs (a new subscription on this device)', () => {
    assert.equal(shouldReconcile(MARKER, 'https://push.example/xyz', MARKER.memberId, NOW, TTL), true);
});

test('POST: the marker is older than the TTL (a cap-pruned row self-heals)', () => {
    assert.equal(shouldReconcile(MARKER, MARKER.endpoint, MARKER.memberId, NOW + TTL + 1, TTL), true);
});

// reconcileOutcome decides what a reconcile POST's status does to the local subscription, given
// whether the marker already proves it is another member's. A 2xx always confirms.
test('confirm: any 2xx is the rebind stored, foreign or not', () => {
    for (const status of [200, 201, 204]) {
        assert.equal(reconcileOutcome(status, false), 'confirm');
        assert.equal(reconcileOutcome(status, true), 'confirm');
    }
});

// Our own / ownership-unknown subscription: a transient status is kept, only a definitive refusal sheds.
test('keep: a transient status on our own subscription is never an unsubscribe', () => {
    for (const status of [408, 425, 429, 500, 502, 503, 504]) {
        assert.equal(reconcileOutcome(status, false), 'keep');
    }
});

test('unsubscribe: a definitive 4xx (auth/CSRF/validation/gone) refuses the rebind', () => {
    for (const status of [400, 401, 403, 404, 419, 422]) {
        assert.equal(reconcileOutcome(status, false), 'unsubscribe');
    }
});

// Known-foreign (marker: same endpoint, different member): ANY non-2xx sheds it, including transient —
// keeping it would leak the prior member's pushes, and the retry is not time-bounded.
test('unsubscribe: a known-foreign subscription is shed on any non-2xx, transient included', () => {
    for (const status of [408, 425, 429, 500, 503, 400, 401, 403, 404, 419, 422]) {
        assert.equal(reconcileOutcome(status, true), 'unsubscribe');
    }
});

// Client-side web push: capability checks, subscribe/unsubscribe for the current device, and the
// VAPID key decode. Sibling import (not the `@/` alias) so `push.test.ts` resolves it under
// `node --test`.
import { xsrfHeader } from './csrf.ts';

/** Whether this browser can register a worker and receive web push at all. */
export function pushSupported(): boolean {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

/**
 * iOS/iPadOS delivers web push only to a site added to the Home Screen, so an ordinary Safari tab can
 * never subscribe. Detect that (iOS UA, not running standalone — iOS exposes both the display-mode
 * query and the legacy `navigator.standalone`) so the UI guides the member to install first instead
 * of offering a Subscribe button that would always fail.
 */
export function isIosNotInstalled(): boolean {
    const nav = navigator as Navigator & { standalone?: boolean };
    const isIos = /iP(hone|ad|od)/.test(nav.userAgent) || (nav.platform === 'MacIntel' && nav.maxTouchPoints > 1);
    if (!isIos) {
        return false;
    }
    const standalone = window.matchMedia('(display-mode: standalone)').matches || nav.standalone === true;
    return !standalone;
}

export function permissionState(): 'unsupported' | 'default' | 'granted' | 'denied' {
    return pushSupported() ? Notification.permission : 'unsupported';
}

export async function currentSubscription(): Promise<PushSubscription | null> {
    if (!pushSupported()) {
        return null;
    }
    const registration = await navigator.serviceWorker.ready;
    return registration.pushManager.getSubscription();
}

/**
 * Subscribe this browser. MUST be called from a user gesture (a button click): the first step asks
 * for the notification permission, which browsers only grant in response to one. Returns 'denied'
 * when permission is refused and 'error' on any failure, so the caller can message without a throw.
 */
export async function subscribeThisDevice(vapidPublicKey: string): Promise<'subscribed' | 'denied' | 'error'> {
    // Rolled back on any failure past subscribe(): "subscribed" must mean the server persisted the
    // row, not merely that this browser created a PushSubscription. A local sub the server never
    // stored would read back as subscribed forever.
    let sub: PushSubscription | null = null;
    try {
        if (await Notification.requestPermission() !== 'granted') {
            return 'denied';
        }
        // register() resolves before the worker is active, but pushManager.subscribe() rejects with
        // no active worker — so wait for `ready`, or the first subscribe on a fresh device fails and
        // only a second attempt (worker now active) succeeds.
        await navigator.serviceWorker.register('/sw.js');
        const registration = await navigator.serviceWorker.ready;
        sub = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
        });
        const json = sub.toJSON();
        const res = await fetch('/push/subscriptions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...xsrfHeader() },
            credentials: 'same-origin',
            body: JSON.stringify({ endpoint: json.endpoint, keys: json.keys }),
        });
        if (!res.ok) {
            await sub.unsubscribe();
            return 'error';
        }
        return 'subscribed';
    } catch {
        if (sub) {
            try {
                await sub.unsubscribe();
            } catch {
                // Already gone; nothing left to undo.
            }
        }
        return 'error';
    }
}

/**
 * Drop this browser's subscription. Local removal is the success condition — a dead endpoint
 * self-expires via the push service's 404/410, so the server delete is best-effort and its answer is
 * not load-bearing. A thrown network error is left to propagate for the caller's finally to handle.
 */
export async function unsubscribeThisDevice(): Promise<void> {
    const sub = await currentSubscription();
    if (!sub) {
        return;
    }
    await fetch('/push/subscriptions/delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...xsrfHeader() },
        credentials: 'same-origin',
        body: JSON.stringify({ endpoint: sub.endpoint }),
    });
    await sub.unsubscribe();
}

/** A confirmed same-member/same-endpoint binding, stored per browser so reconcile can skip the POST. */
interface PushBinding {
    endpoint: string;
    memberId: number;
    at: number;
}

const PUSH_BOUND_KEY = 'openpne-push-bound';

/**
 * How long a confirmed binding is trusted before reconcile re-POSTs to re-confirm it. Short enough
 * that a cap-pruned server row self-heals within a day; long enough that ordinary browsing never
 * spends the store route's rate limit.
 */
const RECONCILE_TTL_MS = 12 * 60 * 60 * 1000;

/**
 * Reconcile only on an ownership transition — no marker, a different member or endpoint, or a marker
 * older than the TTL — never on a confirmed same-member binding. Re-confirming an unchanged binding on
 * every load would consume the store route's `throttle:30,1` and, on the 429, fail closed and
 * unsubscribe the member's own device. Pure so push.test.ts pins it; the Classic vanilla script mirrors
 * this predicate inline.
 */
export function shouldReconcile(
    marker: PushBinding | null,
    endpoint: string,
    memberId: number,
    now: number,
    ttl: number,
): boolean {
    if (!marker) {
        return true;
    }
    return !(marker.endpoint === endpoint && marker.memberId === memberId && now - marker.at < ttl);
}

/**
 * What a reconcile POST's status means for the local subscription. A 2xx is the rebind confirmed. A
 * 429 or any 5xx is the server not answering, not refusing — the binding may well be fine, so keep it
 * and let the next navigation retry. Only a definitive 4xx (auth, CSRF, validation, unconfigured) is
 * the reclaim refused, which fails closed. Pure so push.test.ts pins it; the Classic script mirrors it.
 */
export function reconcileOutcome(status: number): 'confirm' | 'keep' | 'unsubscribe' {
    if (status >= 200 && status < 300) {
        return 'confirm';
    }
    if (status === 429 || status >= 500) {
        return 'keep';
    }
    return 'unsubscribe';
}

/** Reads the binding marker, degrading a blocked/broken store (private mode throws) to "no marker". */
function readBinding(): PushBinding | null {
    try {
        const raw = localStorage.getItem(PUSH_BOUND_KEY);
        return raw ? (JSON.parse(raw) as PushBinding) : null;
    } catch {
        return null;
    }
}

function writeBinding(binding: PushBinding): void {
    try {
        localStorage.setItem(PUSH_BOUND_KEY, JSON.stringify(binding));
    } catch {
        // Blocked storage degrades to "no marker": reconcile will POST each load, but a 429 no longer
        // unsubscribes, so the regression stays closed even without storage.
    }
}

function clearBinding(): void {
    try {
        localStorage.removeItem(PUSH_BOUND_KEY);
    } catch {
        // Same degradation as writeBinding.
    }
}

/**
 * Rebind this browser's existing subscription to the member signed in now, failing *closed*. A push
 * row belongs to whoever last POSTed its endpoint, so on a shared browser (A subscribes, logs out; B
 * logs in) the server-side owner stays A while this browser still holds A's subscription — B would see
 * "subscribed" and receive A's pushes. Re-POSTing the endpoint reclaims ownership for the current
 * member and also heals a cap-pruned row (server row gone, browser sub present).
 *
 * The POST fires only on an ownership transition ({@link shouldReconcile}) — a confirmed same-member
 * binding costs zero requests, so ordinary browsing never trips the store route's rate limit. When it
 * does fire and the server *refuses* the rebind — a definitive 4xx ({@link reconcileOutcome}) — the
 * local subscription is unsubscribed so the endpoint dies and the prior owner's push can no longer
 * land: an unconfirmed rebind is a cross-account leak, so privacy wins over convenience. A 429, a 5xx
 * or a dropped request is the server not answering rather than refusing, so the subscription is left
 * alone and the next navigation retries — a transient outage must not unsubscribe a member's own
 * device. `getRegistration()` (not `.ready`, which never resolves when no worker is registered) keeps
 * a member who never subscribed a no-op, and with no existing subscription this never subscribes a
 * fresh browser — a silent subscribe is not consent.
 */
export async function reconcileSubscription(memberId: number): Promise<void> {
    if (!pushSupported() || Notification.permission !== 'granted') {
        return;
    }
    const registration = await navigator.serviceWorker.getRegistration();
    const sub = await registration?.pushManager.getSubscription();
    if (!sub) {
        return;
    }
    if (!shouldReconcile(readBinding(), sub.endpoint, memberId, Date.now(), RECONCILE_TTL_MS)) {
        return;
    }
    let outcome: 'confirm' | 'keep' | 'unsubscribe';
    try {
        const json = sub.toJSON();
        const res = await fetch('/push/subscriptions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...xsrfHeader() },
            credentials: 'same-origin',
            body: JSON.stringify({ endpoint: json.endpoint, keys: json.keys }),
        });
        outcome = reconcileOutcome(res.status);
    } catch {
        // A dropped request is the server not answering, not refusing — keep the subscription and let
        // the next navigation retry, the same as a 429 or 5xx.
        outcome = 'keep';
    }
    if (outcome === 'confirm') {
        writeBinding({ endpoint: sub.endpoint, memberId, at: Date.now() });
        return;
    }
    if (outcome === 'keep') {
        // Marker unwritten, so the next navigation retries until the rebind sticks.
        return;
    }
    clearBinding();
    await sub.unsubscribe();
}

/**
 * Re-register the worker on load, but only for a device that already granted permission, so an
 * opted-in browser fetches an updated /sw.js each visit. Never installs a worker for a member who
 * never opted in.
 */
export function resumeRegistration(): void {
    if (pushSupported() && Notification.permission === 'granted') {
        void navigator.serviceWorker.register('/sw.js');
    }
}

/** Best-effort OS app-icon badge (Badging API — feature-detected; absent in many browsers/TS libs). */
export function setAppBadge(count: number): void {
    const nav = navigator as Navigator & { setAppBadge?: (n?: number) => Promise<unknown> };
    void nav.setAppBadge?.(count).catch(() => {});
}

export function clearAppBadge(): void {
    const nav = navigator as Navigator & { clearAppBadge?: () => Promise<unknown> };
    void nav.clearAppBadge?.().catch(() => {});
}

/**
 * Decode a base64url VAPID public key to raw bytes: PushManager.subscribe wants the key's bytes as
 * applicationServerKey, not its string form, so the P-256 point round-trips intact. Exported so the
 * decode — the one pure, security-adjacent piece here — can be unit-tested.
 */
export function urlBase64ToUint8Array(base64: string): Uint8Array<ArrayBuffer> {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const normalized = (base64 + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(normalized);
    const output = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) {
        output[i] = raw.charCodeAt(i);
    }
    return output;
}

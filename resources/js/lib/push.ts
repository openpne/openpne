// Sibling import (not the `@/` alias) so `push.test.ts` resolves it under `node --test`.
import { xsrfHeader } from './csrf.ts';

export function pushSupported(): boolean {
    return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

/**
 * iOS/iPadOS delivers web push only to a site added to the Home Screen, so an ordinary Safari tab can
 * never subscribe. Detected as the iOS UA not running standalone, which iOS reports through both the
 * display-mode query and the legacy `navigator.standalone`.
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
 * MUST be called from a user gesture: the first step asks for the notification permission, which
 * browsers only grant in response to one. Returns 'denied' when permission is refused and 'error' on
 * any failure, so the caller can message without a throw.
 */
export async function subscribeThisDevice(vapidPublicKey: string): Promise<'subscribed' | 'denied' | 'error'> {
    // Rolled back on any failure past subscribe(), because a local subscription the server never
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
 * Local removal is the success condition — a dead endpoint self-expires via the push service's
 * 404/410, so the server delete is best-effort. A thrown network error is left to propagate for the
 * caller's finally to handle.
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
 * Re-confirming an unchanged binding on every load would consume the store route's `throttle:30,1`
 * and, on the 429, unsubscribe the member's own device (docs/internals/notifications.md, "Web
 * push"). The Classic vanilla script mirrors this predicate inline.
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

/** The statuses the store route (or a proxy in front of it) returns to *refuse* a rebind outright —
 *  as opposed to a transient/retryable status (408/425/429/5xx) or the endpoint simply not answering. */
const DEFINITIVE_REFUSAL = new Set([400, 401, 403, 404, 419, 422]);

/**
 * A known-foreign subscription — same endpoint, different member — is shed on any non-2xx, while our
 * own or an ownership-unknown one is shed only on a definitive refusal (docs/internals/notifications.md,
 * "Web push"). The Classic script mirrors it.
 */
export function reconcileOutcome(status: number, knownForeign: boolean): 'confirm' | 'keep' | 'unsubscribe' {
    if (status >= 200 && status < 300) {
        return 'confirm';
    }
    if (knownForeign) {
        return 'unsubscribe';
    }
    return DEFINITIVE_REFUSAL.has(status) ? 'unsubscribe' : 'keep';
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
 * A push row belongs to whoever last POSTed its endpoint, so this re-POST reclaims ownership for the
 * member signed in now and heals a cap-pruned row (docs/internals/notifications.md, "Web push").
 * `getRegistration()`, not `.ready` which never resolves when no worker is registered, keeps a member
 * who never subscribed a no-op, and with no existing subscription this never subscribes a fresh
 * browser.
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
    const marker = readBinding();
    if (!shouldReconcile(marker, sub.endpoint, memberId, Date.now(), RECONCILE_TTL_MS)) {
        return;
    }
    const knownForeign = marker !== null && marker.endpoint === sub.endpoint && marker.memberId !== memberId;
    let outcome: 'confirm' | 'keep' | 'unsubscribe';
    try {
        const json = sub.toJSON();
        const res = await fetch('/push/subscriptions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...xsrfHeader() },
            credentials: 'same-origin',
            body: JSON.stringify({ endpoint: json.endpoint, keys: json.keys }),
        });
        outcome = reconcileOutcome(res.status, knownForeign);
    } catch {
        // A dropped request is the server not answering, so only a known-foreign subscription is
        // shed here.
        outcome = knownForeign ? 'unsubscribe' : 'keep';
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
 * PushManager.subscribe wants the key's bytes as applicationServerKey, not its string form, so the
 * P-256 point round-trips intact.
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

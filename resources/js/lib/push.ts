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
    try {
        if (await Notification.requestPermission() !== 'granted') {
            return 'denied';
        }
        const registration = await navigator.serviceWorker.register('/sw.js');
        const sub = await registration.pushManager.subscribe({
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
        return res.ok ? 'subscribed' : 'error';
    } catch {
        return 'error';
    }
}

/** Drop this browser's subscription, both server-side and locally. Tolerates an already-gone sub. */
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

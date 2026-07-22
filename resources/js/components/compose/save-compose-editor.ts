import { xsrfHeader } from '@/lib/csrf';
import type { ComposeEditorPreference } from './editor-mode';
import { createLastIntentQueue } from './last-intent-queue';

/** Always resolves — a failed save must not strand the choices queued behind it. */
function post(method: ComposeEditorPreference): Promise<void> {
    return fetch('/compose/editor', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...xsrfHeader() },
        body: JSON.stringify({ compose_editor: method }),
    })
        .then(() => {})
        .catch(() => {});
}

const queue = createLastIntentQueue(post);

/**
 * Persist the member's input-method choice — fire-and-forget from the caller's side, since the form
 * has already switched. Follows the raw-fetch contract of markdown-preview.tsx: JSON content/accept
 * headers plus the XSRF header. A failed request is swallowed and never retried — the choice simply
 * isn't persisted this time. Requests are serialized and coalesced to the last choice made, so a
 * quick run through the menu cannot store an earlier one.
 */
export function saveComposeEditor(method: ComposeEditorPreference): void {
    queue(method);
}

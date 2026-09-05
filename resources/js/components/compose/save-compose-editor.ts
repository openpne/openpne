import { xsrfHeader } from '@/lib/csrf';
import type { ComposeEditorPreference } from './editor-mode';
import { createLastIntentQueue } from './last-intent-queue';

/** Rejects on a refused write; the queue drops it and carries on with whatever is behind it. */
function post(method: ComposeEditorPreference): Promise<void> {
    return fetch('/compose/editor', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...xsrfHeader() },
        body: JSON.stringify({ compose_editor: method }),
    }).then((res) => {
        if (!res.ok) {
            throw new Error(`save compose editor failed: ${res.status}`);
        }
    });
}

const queue = createLastIntentQueue(post);

/**
 * Fire-and-forget: a failed write is swallowed and never retried, so the choice is simply not
 * persisted this time.
 */
export function saveComposeEditor(method: ComposeEditorPreference): void {
    queue(method);
}

import { xsrfHeader } from '@/lib/csrf';
import type { ComposeEditorPreference } from './editor-mode';

/**
 * Persist the member's compose-editor choice — a fire-and-forget POST that never blocks the UI (the
 * mode has already flipped in the open form). Follows the raw-fetch contract of markdown-preview.tsx:
 * JSON content/accept headers plus the XSRF header. A non-ok response is swallowed and never retried
 * nor treated as success — the choice simply isn't persisted this time.
 */
export function saveComposeEditor(mode: ComposeEditorPreference): void {
    fetch('/compose/editor', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...xsrfHeader() },
        body: JSON.stringify({ compose_editor: mode }),
    })
        .then((res) => {
            if (!res.ok) {
                throw new Error(`save compose editor failed: ${res.status}`);
            }
        })
        .catch(() => {});
}

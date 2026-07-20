import { useEffect, useState } from 'react';
import { RichBody } from '@/components/rich-body';
import { xsrfHeader } from '@/lib/csrf';
import { useT } from '@/lib/i18n';

/**
 * Live Markdown preview for the compose forms. Debounces edits, then POSTs to /compose/preview (the
 * same sanitized pipeline as a stored body) and renders the returned HTML through <RichBody>, so the
 * preview can never show markup the saved body would strip. Renders nothing until enabled with a
 * non-empty body. An in-flight request is aborted when the body changes or the component unmounts.
 */
export function MarkdownPreview({ body, enabled }: { body: string; enabled: boolean }) {
    const t = useT();
    const [html, setHtml] = useState<string | null>(null);
    const [state, setState] = useState<'idle' | 'pending' | 'error'>('idle');
    const active = enabled && body.trim() !== '';

    useEffect(() => {
        if (!active) {
            setHtml(null);
            setState('idle');

            return;
        }

        const controller = new AbortController();
        setState('pending');
        const timer = setTimeout(() => {
            fetch('/compose/preview', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...xsrfHeader() },
                body: JSON.stringify({ body }),
                signal: controller.signal,
            })
                .then((res) => {
                    if (!res.ok) {
                        throw new Error(`preview failed: ${res.status}`);
                    }

                    return res.json() as Promise<{ html: string }>;
                })
                .then((data) => {
                    setHtml(data.html);
                    setState('idle');
                })
                .catch(() => {
                    if (controller.signal.aborted) {
                        return; // superseded by a newer edit or unmount; keep the current view
                    }
                    setState('error');
                });
        }, 500);

        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [active, body]);

    if (!active) {
        return null;
    }

    return (
        // aria-busy marks the async refresh; the error line is a status region so a screen reader
        // hears the failure (the rendered body itself is not announced — re-reading the whole
        // preview on every debounce would be noise).
        <div className="space-y-1" aria-busy={state === 'pending'}>
            <p className="text-xs font-medium text-muted-foreground">{t('Preview')}</p>
            {state === 'error' ? (
                <p className="text-xs text-muted-foreground" role="status">
                    {t('Preview unavailable.')}
                </p>
            ) : html !== null ? (
                <div className={state === 'pending' ? 'opacity-60 transition-opacity' : undefined}>
                    <RichBody body={body} bodyHtml={html} />
                </div>
            ) : (
                <p className="text-xs text-muted-foreground" aria-hidden="true">
                    …
                </p>
            )}
        </div>
    );
}

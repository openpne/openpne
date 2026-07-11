import { router } from '@inertiajs/react';
import { useLaravelReactI18n } from 'laravel-react-i18n';
import { useEffect } from 'react';
import type { PageProps } from '@/types';

/**
 * Keeps the i18n provider's active locale in step with the server-resolved `locale` shared on
 * every Inertia response. The provider reads its locale once at boot, but the server locale can
 * change without a full page load: logging in switches to the member's durable locale and logging
 * out falls back to the session/Accept-Language — both arrive as XHR navigations. Without this
 * the dictionary would keep the boot locale while the per-request `terms` prop moved on,
 * rendering two languages on one screen. Dictionaries are eager-bundled, so the switch is sync.
 *
 * Lives here rather than in the app entry so the entry stays a pure side-effect module (see app.tsx).
 */
export function SyncLocaleWithServer() {
    const { currentLocale, setLocale } = useLaravelReactI18n();

    // No dependency array: currentLocale/setLocale are recreated per provider render, so
    // re-subscribing each render (router.on returns its own unsubscriber) keeps them fresh.
    useEffect(() => {
        return router.on('navigate', (event) => {
            const next = (event.detail.page.props as unknown as PageProps).locale;
            if (next && next !== currentLocale()) {
                // Twice on purpose. t() resolves a key missing from the active dictionary from
                // prevLocale BEFORE the fallback, and the en dictionary is sparse by design
                // (source-text keys), so a single ja→en switch would keep rendering ja strings.
                // setLocale has no same-value early return, so the second call collapses
                // prevLocale onto the new locale and misses fall through to the raw English key.
                // (setLocale also maintains <html lang> itself.)
                setLocale(next);
                setLocale(next);
            }
        });
    });

    return null;
}

import { router } from '@inertiajs/react';
import { useLaravelReactI18n } from 'laravel-react-i18n';
import { useEffect } from 'react';
import type { PageProps } from '@/types';

/**
 * The provider reads its locale once at boot, but the server locale changes without a full page load
 * — a login or logout arrives as an XHR navigation (docs/internals/i18n.md, "Inertia / React
 * wiring"). Lives here rather than in the app entry, so the entry stays a pure side-effect module.
 */
export function SyncLocaleWithServer() {
    const { currentLocale, setLocale } = useLaravelReactI18n();

    // No dependency array: currentLocale/setLocale are recreated per provider render, so
    // re-subscribing each render (router.on returns its own unsubscriber) keeps them fresh.
    useEffect(() => {
        return router.on('navigate', (event) => {
            const next = (event.detail.page.props as unknown as PageProps).locale;
            if (next && next !== currentLocale()) {
                // Twice on purpose: t() resolves a key missing from the active dictionary from
                // prevLocale before the fallback, and the second call collapses prevLocale onto the
                // new locale.
                setLocale(next);
                setLocale(next);
            }
        });
    });

    return null;
}

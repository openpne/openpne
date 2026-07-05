import { createInertiaApp, type ResolvedComponent, router } from '@inertiajs/react';
import { LaravelReactI18nProvider, useLaravelReactI18n } from 'laravel-react-i18n';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { type ReactNode, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { AppShell } from '@/components/app-shell';
// Side-effect import: applies the saved color mode, keeps <meta name="theme-color"> in sync, and
// installs the OS prefers-color-scheme listener on every Modern page (the useColorMode UI lives only
// on the settings page, so without this the listener/sync would load lazily with that page).
import '@/lib/color-mode';
import type { PageProps } from '@/types';

// Set at mount from the shared Inertia `name` prop (sns_name()) so Modern titles track the
// per-site name like Classic. VITE_APP_NAME is only the pre-mount fallback; site name is
// treated as site-invariant, so capturing the initial page's value is enough.
let appName = import.meta.env.VITE_APP_NAME ?? 'OpenPNE';

/**
 * Keeps the i18n provider's active locale in step with the server-resolved `locale` shared on
 * every Inertia response. The provider reads its locale once at boot, but the server locale can
 * change without a full page load: logging in switches to the member's durable locale and logging
 * out falls back to the session/Accept-Language — both arrive as XHR navigations. Without this
 * the dictionary would keep the boot locale while the per-request `terms` prop moved on,
 * rendering two languages on one screen. Dictionaries are eager-bundled, so the switch is sync.
 */
function SyncLocaleWithServer() {
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

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: async (name) => {
        const page = await resolvePageComponent<ResolvedComponent>(
            `./pages/${name}.tsx`,
            import.meta.glob<ResolvedComponent>('./pages/**/*.tsx'),
        );
        // resolvePageComponent resolves to the page *module*; the component and its optional
        // persistent layout live on `.default` (the helper's return type says component, but at
        // runtime it is the module). Wrap every non-auth page in the shell (nav chrome only — the
        // page keeps its own <main>/flash); auth/* keep their own AuthLayout, and any page can opt
        // out with its own `layout`. Gate on `layout === undefined` (not null) — Inertia React treats
        // a null layout as "use the default".
        const mod = page as unknown as { default: { layout?: (el: ReactNode) => ReactNode } };
        if (mod.default.layout === undefined && !name.startsWith('auth/')) {
            mod.default.layout = (pageEl: ReactNode) => <AppShell>{pageEl}</AppShell>;
        }
        return page;
    },
    setup({ el, App, props }) {
        appName = (props.initialPage.props as PageProps).name || appName;
        // `fallbackLocale="en"` (not the app default `ja`) so that an en miss
        // surfaces as the raw English key — matching the "key === English
        // text" omission policy. ja-as-fallback would silently render Japanese
        // when the en bundle is intentionally empty.
        const locale = (props.initialPage.props as PageProps).locale;
        createRoot(el).render(
            <LaravelReactI18nProvider
                locale={locale}
                fallbackLocale="en"
                // Eager so the active locale's dictionary is present on the first paint. A lazy glob
                // loads it in a post-mount effect, so the first render shows raw (English) keys and
                // then swaps to the translation — a visible flash on every full Modern load, e.g. a
                // Classic→Modern surface switch. Eager bundles ja/en (small, flat dicts) instead.
                files={import.meta.glob('/lang/*.json', { eager: true })}
            >
                <SyncLocaleWithServer />
                <App {...props} />
            </LaravelReactI18nProvider>,
        );
    },
    progress: {
        color: '#4f46e5',
    },
});

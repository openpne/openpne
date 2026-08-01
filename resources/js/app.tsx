import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { LaravelReactI18nProvider } from 'laravel-react-i18n';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { MemberLayout } from '@/components/member-layout';
import { SyncLocaleWithServer } from '@/components/sync-locale';
// Side-effect import: applies the saved color mode, keeps <meta name="theme-color"> in sync, and
// installs the OS prefers-color-scheme listener on every Modern page (the useColorMode UI lives only
// on the settings page, so without this the listener/sync would load lazily with that page).
import '@/lib/color-mode';
import { unreadTitleCount, withUnreadPrefix } from '@/lib/unread-title';
import type { PageProps } from '@/types';

// Keep this entry free of React component definitions: a component here makes the module a Vite
// Fast Refresh boundary, and plugin-react's boundary self-import re-executes the entry's top-level
// createRoot in dev, mounting the app twice. Put shell components in their own module (see
// SyncLocaleWithServer). This module must stay a pure side-effect that mounts exactly once.

// Set at mount from the shared Inertia `name` prop (sns_name()) so Modern titles track the
// per-site name like Classic. VITE_APP_NAME is only the pre-mount fallback; site name is
// treated as site-invariant, so capturing the initial page's value is enough.
let appName = import.meta.env.VITE_APP_NAME ?? 'OpenPNE';

void createInertiaApp({
    // The unread prefix is applied here rather than by writing document.title, because the head
    // manager owns that write and debounces it (see @/lib/unread-title).
    title: (title) => withUnreadPrefix(title ? `${title} - ${appName}` : appName, unreadTitleCount()),
    resolve: (name) =>
        resolvePageComponent<ResolvedComponent>(
            `./pages/${name}.tsx`,
            import.meta.glob<ResolvedComponent>('./pages/**/*.tsx'),
        ),
    // Default layout for every non-auth page: nav chrome + the member page frame (single <main>,
    // hub header from the chrome registry, central flash). auth/* keep their own AuthLayout. A page
    // overrides its frame via `Page.layout = (props) => ({ chrome: {…} })` (Inertia merges the
    // object into this layout's props); returning a component instead replaces the layout entirely.
    layout: (name: string) => (name.startsWith('auth/') ? undefined : MemberLayout),
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

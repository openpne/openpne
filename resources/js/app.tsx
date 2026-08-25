import { createInertiaApp, type ResolvedComponent, router } from '@inertiajs/react';
import { LaravelReactI18nProvider } from 'laravel-react-i18n';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { MemberLayout } from '@/components/member-layout';
import { SyncLocaleWithServer } from '@/components/sync-locale';
import { TooltipProvider } from '@/components/ui/tooltip';
// Side-effect import: applies the saved color mode, keeps <meta name="theme-color"> in sync, and
// installs the OS prefers-color-scheme listener on every Modern page (the useColorMode UI lives only
// on the settings page, so without this the listener/sync would load lazily with that page).
import '@/lib/color-mode';
import { installBackNav } from '@/lib/back-nav';
import { conversationVisitOptions } from '@/lib/chat/opening-scroll';
import { installHistoryRestore } from '@/lib/history-restore';
import { pageModules, pagePath } from '@/lib/page-modules';
import { installRevalidateOnRestore } from '@/lib/revalidate-on-restore';
import { withUnreadPrefix } from '@/lib/unread-title';
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
    // manager owns that write and debounces it. Reading the count from the callback's page argument
    // keeps auth pages clean: they share no `unread`, so no prefix survives a SPA logout.
    title: (title, page) =>
        withUnreadPrefix(title ? `${title} - ${appName}` : appName, (page.props as PageProps).unread?.notifications ?? 0),
    defaults: {
        // Asked for every visit, and the one place a destination can decline being scrolled by
        // Inertia — see lib/chat/opening-scroll.ts for why a conversation has to.
        visitOptions: (_href: string, options: { preserveScroll?: unknown }) => conversationVisitOptions(options),
    },
    resolve: (name) => resolvePageComponent<ResolvedComponent>(pagePath(name), pageModules),
    // Default layout for every non-auth page: nav chrome + the member page frame (single <main>,
    // hub header from the chrome registry, central flash). auth/* keep their own AuthLayout. A page
    // overrides its frame via `Page.layout = (props) => ({ chrome: {…} })` (Inertia merges the
    // object into this layout's props); returning a component instead replaces the layout entirely.
    layout: (name: string) => (name.startsWith('auth/') ? undefined : MemberLayout),
    setup({ el, App, props }) {
        appName = (props.initialPage.props as PageProps).name || appName;
        // Before the app mounts, so the first `navigate` (the initial load) is counted as the
        // session's floor rather than as a step the detail bar could offer to go back from.
        installBackNav(router);
        // Before the app mounts likewise: a page that revalidates on a restore has to find the
        // record already there when it arrives.
        installHistoryRestore();
        // Every page restored from history is re-read from the server — see the module for why
        // this is the default rather than something each page opts into.
        installRevalidateOnRestore(router);
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
                {/* One provider for the whole app, not one per tooltip: `skipDelayDuration` is what
                    makes the second of two neighbouring icons answer instantly, and it is shared
                    state — per-tooltip providers would each start their own delay again. */}
                {/* disableHoverableContent: these are labels, not panels to mouse into — and a
                    hoverable panel floats over the neighbouring icon and eats the pointer that
                    would have raised that icon's own label. */}
                <TooltipProvider delayDuration={500} skipDelayDuration={300} disableHoverableContent>
                    <App {...props} />
                </TooltipProvider>
            </LaravelReactI18nProvider>,
        );
    },
    progress: {
        color: '#4f46e5',
    },
});

import { createInertiaApp, type ResolvedComponent, router } from '@inertiajs/react';
import { LaravelReactI18nProvider } from 'laravel-react-i18n';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { MemberLayout } from '@/components/member-layout';
import { SyncLocaleWithServer } from '@/components/sync-locale';
import { TooltipProvider } from '@/components/ui/tooltip';
// Imported here, not from the settings page's `useColorMode`, so the color-mode listener installs
// on every Modern page.
import '@/lib/color-mode';
import { installBackNav } from '@/lib/back-nav';
import { conversationVisitOptions } from '@/lib/chat/opening-scroll';
import { installHistoryRestore } from '@/lib/history-restore';
import { installNotificationOpen } from '@/lib/notification-open';
import { pageModules, pagePath } from '@/lib/page-modules';
import { installRevalidateOnRestore } from '@/lib/revalidate-on-restore';
import { withUnreadPrefix } from '@/lib/unread-title';
import type { PageProps } from '@/types';

// Keep this entry free of React component definitions: a component makes it a Vite Fast Refresh
// boundary, and plugin-react's boundary self-import re-runs the top-level createRoot in dev,
// mounting the app twice.

// The site name is site-invariant, so capturing the initial page's value is enough; VITE_APP_NAME
// is only the pre-mount fallback.
let appName = import.meta.env.VITE_APP_NAME ?? 'OpenPNE';

// Installed from the module top level, before DOMContentLoaded: a listener added any later misses
// a notification tap.
const notificationOpen = installNotificationOpen((url) => router.visit(url));
const offFirstNavigate = router.on('navigate', () => {
    offFirstNavigate();
    notificationOpen.ready();
});

void createInertiaApp({
    // The head manager owns the title write (docs/internals/notifications.md, "Liveness"); auth
    // pages share no `unread`, so no prefix survives a SPA logout.
    title: (title, page) =>
        withUnreadPrefix(title ? `${title} - ${appName}` : appName, (page.props as PageProps).unread?.notifications ?? 0),
    defaults: {
        // Inertia asks this for every visit, and it is the one place a destination can decline
        // being scrolled.
        visitOptions: (_href: string, options: { preserveScroll?: unknown }) => conversationVisitOptions(options),
    },
    resolve: (name) => resolvePageComponent<ResolvedComponent>(pagePath(name), pageModules),
    // A page overrides its frame by returning an object from `Page.layout` (Inertia merges it into
    // this layout's props); returning a component instead replaces the layout entirely.
    layout: (name: string) => (name.startsWith('auth/') ? undefined : MemberLayout),
    setup({ el, App, props }) {
        appName = (props.initialPage.props as PageProps).name || appName;
        // Before the app mounts, so the first `navigate` (the initial load) is counted as the
        // session's floor rather than as a step the detail bar could offer to go back from.
        installBackNav(router);
        // Before the app mounts likewise: a page that revalidates on a restore has to find the
        // record already there when it arrives.
        installHistoryRestore();
        installRevalidateOnRestore(router);
        const locale = (props.initialPage.props as PageProps).locale;
        createRoot(el).render(
            <LaravelReactI18nProvider
                locale={locale}
                // See docs/internals/i18n.md, "Inertia / React wiring".
                fallbackLocale="en"
                // Eager so the active locale's dictionary is present on the first paint; a lazy
                // glob loads it in a post-mount effect, so the first render shows raw English keys
                // and then swaps.
                files={import.meta.glob('/lang/*.json', { eager: true })}
            >
                <SyncLocaleWithServer />
                {/* One provider for the whole app: `skipDelayDuration` is shared state, so
                    per-tooltip providers would each start their own delay again. */}
                {/* disableHoverableContent: a hoverable panel floats over the neighbouring icon and
                    eats the pointer that would have raised that icon's own label. */}
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

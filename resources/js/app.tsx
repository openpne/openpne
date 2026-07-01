import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
import { LaravelReactI18nProvider } from 'laravel-react-i18n';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import { AppShell } from '@/components/app-shell';
import type { PageProps } from '@/types';

// Set at mount from the shared Inertia `name` prop (sns_name()) so Modern titles track the
// per-site name like Classic. VITE_APP_NAME is only the pre-mount fallback; site name is
// treated as site-invariant, so capturing the initial page's value is enough.
let appName = import.meta.env.VITE_APP_NAME ?? 'OpenPNE';

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
                <App {...props} />
            </LaravelReactI18nProvider>,
        );
    },
    progress: {
        color: '#4f46e5',
    },
});

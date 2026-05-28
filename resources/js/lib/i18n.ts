import { usePage } from '@inertiajs/react';
import { useLaravelReactI18n } from 'laravel-react-i18n';

import type { PageProps } from '@/types';

/**
 * Thin wrapper around `useLaravelReactI18n().t` so call sites import a single
 * project-local helper. After the base lookup, any `%name%` placeholders are
 * substituted with the resolved term map shipped via Inertia props. Pre-computed
 * server-side as case/plural variants so this stays a flat dictionary read.
 *
 * Only the string-returning form of `t()` is exposed. The package also has a
 * choice/plural form (`tChoice`) — wrap that separately when needed so callers
 * stay type-safe instead of silently coercing here.
 */
export function useT(): (
    key: string,
    replacements?: Record<string, string | number>,
) => string {
    const { t } = useLaravelReactI18n();
    const terms = usePage<PageProps>().props.terms ?? {};

    return (key, replacements) => {
        const raw = t(key, replacements);
        if (typeof raw !== 'string' || !raw.includes('%')) {
            return raw as string;
        }

        return raw.replace(/%([a-zA-Z_]+)%/g, (full, name) => terms[name] ?? full);
    };
}

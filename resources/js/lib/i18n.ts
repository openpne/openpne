import { usePage } from '@inertiajs/react';
import { useLaravelReactI18n } from 'laravel-react-i18n';

import type { PageProps } from '@/types';

/**
 * After the base lookup, `%name%` placeholders are substituted with the term map shipped via Inertia
 * props (docs/internals/i18n.md, "Term placeholders"). Only the string-returning form of `t()` is
 * exposed; the package's choice/plural form is wrapped separately when it is needed.
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

import { useLaravelReactI18n } from 'laravel-react-i18n';

/**
 * Thin wrapper around `useLaravelReactI18n().t` so call sites import a single
 * project-local helper. Currently a straight passthrough; reserved as the hook
 * point where future SNS-term placeholder substitution will be re-added.
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

    return (key, replacements) => t(key, replacements);
}

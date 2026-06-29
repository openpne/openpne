// `laravel-react-i18n/vite` is a JS shim with no co-located types (the real
// declaration lives at `dist/vite`, whose return type is not assignable to a
// Vite plugin). Declare the entry as an ambient module typed as a PluginOption
// so it slots into the plugins array. Kept as a global-script .d.ts (no
// top-level import) so `declare module` declares the module rather than
// augmenting it.
declare module 'laravel-react-i18n/vite' {
    const i18n: (config?: {
        langDirname?: string;
        typeDestinationPath?: string;
        typeTranslationKeys?: boolean;
    }) => import('vite').PluginOption;

    export default i18n;
}

/**
 * The `t` a component test stands in for useT with. Renders the key with its replacements
 * substituted, the way Laravel would, so a marker phrase (`:name (AI)`) comes back assembled rather
 * than as its key. Mirrors the stub identity-mark.test.ts uses; shared because every component test
 * that renders a name needs it.
 */
export const fakeT = (key: string, replacements: Record<string, string | number> = {}): string =>
    Object.entries(replacements).reduce((line, [name, value]) => line.replaceAll(`:${name}`, String(value)), key);

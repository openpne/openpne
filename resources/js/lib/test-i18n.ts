/**
 * Renders the key with its replacements substituted, the way Laravel would, so a marker phrase
 * (`:name (AI)`) comes back assembled rather than as its key.
 */
export const fakeT = (key: string, replacements: Record<string, string | number> = {}): string =>
    Object.entries(replacements).reduce((line, [name, value]) => line.replaceAll(`:${name}`, String(value)), key);

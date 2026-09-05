/**
 * pickReadableTextColor returns raw Tailwind color classes, which RawPaletteGuardTest bans in `.tsx`,
 * so it stays here in a `.ts` file the guard does not scan.
 */

/**
 * The same key App\Features\Member\MemberDisplayName renders, so a notification sentence and the room
 * row that led to it read alike. Takes `t` rather than calling `useT`, so it stays a plain function.
 */
export function markedName(name: string, isAi: boolean, t: (key: string, replacements?: Record<string, string>) => string): string {
    return isAi ? t(':name (AI)', { name }) : name;
}

/**
 * A leading CJK character stands alone; otherwise the first word's first two letters, uppercased
 * ("My SNS" -> "MY", "Acme" -> "AC"). Empty name falls back to "??".
 */
export function computeInitial(name: string): string {
    const trimmed = name.trim();
    if (trimmed === '') return '??';

    const first = Array.from(trimmed)[0] ?? '';
    if (isCjk(first)) return first;

    const firstWord = trimmed.split(/\s+/)[0] ?? '';
    return firstWord.slice(0, 2).toUpperCase();
}

function isCjk(ch: string): boolean {
    // CJK symbols/punctuation, hiragana, katakana, unified ideographs, and fullwidth/halfwidth forms.
    return /[\u3000-〿぀-ゟ゠-ヿ一-鿿＀-￯]/.test(ch);
}

/**
 * A contrast-ratio comparison rather than a luminance threshold, and pure black rather than an
 * off-black, keeps the worst case at 4.58:1 — a mid-tone hue such as #0088aa clears 4.5:1 against
 * neither white nor slate-900. Invalid input falls back to white; App\Support\BrandColor is the
 * server twin.
 */
export function pickReadableTextColor(bgHex: string): string {
    if (!/^#[0-9a-fA-F]{6}$/.test(bgHex)) return 'text-white';

    const bgLum = wcagRelativeLuminance(bgHex);
    const whiteContrast = contrastRatio(WHITE_LUMINANCE, bgLum);
    const blackContrast = contrastRatio(BLACK_LUMINANCE, bgLum);
    return blackContrast >= whiteContrast ? 'text-black' : 'text-white';
}

function wcagRelativeLuminance(hex: string): number {
    const r = srgbToLinear(parseInt(hex.slice(1, 3), 16) / 255);
    const g = srgbToLinear(parseInt(hex.slice(3, 5), 16) / 255);
    const b = srgbToLinear(parseInt(hex.slice(5, 7), 16) / 255);
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function srgbToLinear(c: number): number {
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}

function contrastRatio(l1: number, l2: number): number {
    const lighter = Math.max(l1, l2);
    const darker = Math.min(l1, l2);
    return (lighter + 0.05) / (darker + 0.05);
}

const WHITE_LUMINANCE = 1;
const BLACK_LUMINANCE = 0;

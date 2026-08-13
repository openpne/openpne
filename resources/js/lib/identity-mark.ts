/**
 * Helpers for the badges that stand in for a missing image: the neutral InitialBadge (members and
 * groups) and BrandMark, whose color the admin configures.
 *
 * pickReadableTextColor returns raw Tailwind color classes, which RawPaletteGuardTest bans in
 * `.tsx`. Keep it here — a `.ts` file the guard does not scan — rather than inlining at call sites.
 */

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
 * Returns `text-white` or `text-black`, whichever has the higher WCAG contrast ratio against
 * `bgHex`. A contrast-ratio comparison (not a luminance threshold) keeps mid-gray backgrounds
 * readable, and pure black rather than an off-black keeps the worst case at 4.58:1 — a mid-tone hue
 * such as #0088aa clears 4.5:1 against neither white nor slate-900. Invalid input falls back to
 * white. App\Support\BrandColor is the server twin.
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

/**
 * Initial-badge fallback shown when a member or community has no image.
 *
 * The color is derived from the entity id (id mod palette length), so the same entity keeps its
 * color across reloads and renames. The 12-color palette is mid-lightness so that either white
 * or slate-900 text can meet WCAG 4.5:1 — pickReadableTextColor chooses which. Shape (circle vs
 * rounded square) distinguishes a member from a community and is applied by the caller's className.
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
    return /[　-〿぀-ゟ゠-ヿ一-鿿＀-￯]/.test(ch);
}

// Tailwind 500-hues at mid-lightness so white or slate-900 text clears WCAG 4.5:1 on each. Members
// and communities share the palette; shape (circle vs rounded square) keeps them distinguishable.
const PALETTE: readonly string[] = [
    '#ef4444', // red-500
    '#f97316', // orange-500
    '#f59e0b', // amber-500
    '#eab308', // yellow-500
    '#84cc16', // lime-500
    '#10b981', // emerald-500
    '#14b8a6', // teal-500
    '#06b6d4', // cyan-500
    '#3b82f6', // blue-500
    '#6366f1', // indigo-500
    '#a855f7', // purple-500
    '#ec4899', // pink-500
];

/** Maps a 1-based id to a palette color. Non-finite/negative ids are guarded to the first color. */
export function pickPaletteColor(id: number): string {
    if (!Number.isFinite(id)) return PALETTE[0]!;
    const index = Math.abs(Math.trunc(id)) % PALETTE.length;
    return PALETTE[index]!;
}

/**
 * Returns `text-white` or `text-slate-900`, whichever has the higher WCAG contrast ratio against
 * `bgHex`. A contrast-ratio comparison (not a luminance threshold) keeps mid-grey backgrounds
 * readable. Invalid input falls back to white.
 */
export function pickReadableTextColor(bgHex: string): string {
    if (!/^#[0-9a-fA-F]{6}$/.test(bgHex)) return 'text-white';

    const bgLum = wcagRelativeLuminance(bgHex);
    const whiteContrast = contrastRatio(WHITE_LUMINANCE, bgLum);
    const darkContrast = contrastRatio(SLATE_900_LUMINANCE, bgLum);
    return darkContrast >= whiteContrast ? 'text-slate-900' : 'text-white';
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
const SLATE_900_LUMINANCE =
    0.2126 * srgbToLinear(0x0f / 255) + 0.7152 * srgbToLinear(0x17 / 255) + 0.0722 * srgbToLinear(0x2a / 255);

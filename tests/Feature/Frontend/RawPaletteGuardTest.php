<?php

namespace Tests\Feature\Frontend;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Raw Tailwind palette classes (numbered shades, bare white/black) are banned in .tsx under
 * resources/js in favor of the semantic design tokens, so a component is dark-correct and
 * re-themeable by construction. White/black is caught too: a converted button can drop its numbered
 * bg yet keep a raw `text-white` meant to be a `-foreground` token.
 */
class RawPaletteGuardTest extends TestCase
{
    /** Numbered palette shades (bg-blue-600, slate-200/50, …). */
    private const PALETTE_NUMBERED = '/\b(?:bg|text|border|ring|from|via|to|divide|fill|stroke|outline|placeholder|caret|accent|decoration|ring-offset)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-(?:50|100|200|300|400|500|600|700|800|900|950)\b/';

    /**
     * White/black classes (text-white, bg-white, bg-black). The only allowed form is a translucent
     * background (bg-black/50 scrim, overlays) — an opacity exception scoped to bg only, so a raw
     * text-white/90 or border-white/20 is still caught.
     */
    private const PALETTE_WHITEBLACK = '/\b(?:(?:text|border|ring|from|via|to|divide|fill|stroke|outline|placeholder|caret|accent|decoration|ring-offset)-(?:white|black)\b|bg-(?:white|black)\b(?!\/))/';

    private function hasRawPalette(string $contents): bool
    {
        return preg_match(self::PALETTE_NUMBERED, $contents) === 1
            || preg_match(self::PALETTE_WHITEBLACK, $contents) === 1;
    }

    /** Screens still on raw palette; empty is the finished state, and an entry only exempts a new screen until it is tokenized. */
    private const ALLOWLIST = [];

    /** @return list<string> */
    private function tsxFiles(): array
    {
        $base = resource_path('js');
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.tsx')) {
                $files[] = str_replace($base.'/', '', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    public function test_no_raw_palette_outside_the_allowlist(): void
    {
        $offenders = [];
        foreach ($this->tsxFiles() as $rel) {
            $contents = (string) file_get_contents(resource_path('js/'.$rel));
            if ($this->hasRawPalette($contents) && ! in_array($rel, self::ALLOWLIST, true)) {
                $offenders[] = $rel;
            }
        }

        $this->assertSame([], $offenders, 'Raw Tailwind palette found — use design tokens (or allowlist consciously): '.implode(', ', $offenders));
    }

    public function test_allowlist_has_no_stale_entries(): void
    {
        $stale = [];
        foreach (self::ALLOWLIST as $rel) {
            $path = resource_path('js/'.$rel);
            if (! is_file($path) || ! $this->hasRawPalette((string) file_get_contents($path))) {
                $stale[] = $rel;
            }
        }

        $this->assertSame([], $stale, 'Allowlisted files no longer contain raw palette (tokenized?) — drop them from the allowlist: '.implode(', ', $stale));
    }
}

<?php

namespace Tests\Feature\Frontend;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Guards the Modern surface against raw Tailwind palette classes: numbered shades (slate-200,
 * blue-600, …) are banned in favor of the semantic design tokens (bg-background / bg-card /
 * text-foreground / text-muted-foreground / border-border / bg-primary / …), so a component is
 * dark-correct and re-themeable by construction.
 *
 * ALLOWLIST holds the screens not yet migrated to tokens; it must only shrink. Intentional exceptions
 * are not matched by the pattern at all: the bg-black/50 dialog scrims (no numeric shade) and the
 * identity-mark hashed colors (inline styles, not classes).
 */
class RawPaletteGuardTest extends TestCase
{
    private const PALETTE = '/\b(?:bg|text|border|ring|from|via|to|divide|fill|stroke|outline|placeholder|caret|accent|decoration|ring-offset)-(?:slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-(?:50|100|200|300|400|500|600|700|800|900|950)\b/';

    /** Screens still on raw palette. Remove each as it is tokenized. */
    private const ALLOWLIST = [
        'pages/community/edit.tsx',
        'pages/community/event/edit.tsx',
        'pages/community/event/show.tsx',
        'pages/community/pending.tsx',
        'pages/community/show.tsx',
        'pages/community/topic/edit.tsx',
        'pages/community/topic/show.tsx',
        'pages/dashboard.tsx',
        'pages/member/avatar.tsx',
        'pages/member/show.tsx',
        'pages/message/compose.tsx',
        'pages/message/edit.tsx',
        'pages/message/index.tsx',
        'pages/message/show.tsx',
    ];

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
            if (preg_match(self::PALETTE, $contents) === 1 && ! in_array($rel, self::ALLOWLIST, true)) {
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
            if (! is_file($path) || preg_match(self::PALETTE, (string) file_get_contents($path)) !== 1) {
                $stale[] = $rel;
            }
        }

        $this->assertSame([], $stale, 'Allowlisted files no longer contain raw palette (tokenized?) — drop them from the allowlist: '.implode(', ', $stale));
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The theme packages found under public/opSkinThemePlugin, read off the filesystem as OpenPNE 3's
 * opThemeAssetSearcher read them (docs/internals/classic-compatibility.md, "Themes").
 */
final class ClassicThemes
{
    /** OpenPNE 3's rule for a theme directory name; the name is also a path segment, so nothing else may pass. */
    private const DIR_PATTERN = '/^[A-Za-z0-9_-]+$/D';

    private const HEADERS = [
        'name' => 'Theme Name',
        'description' => 'Description',
        'author' => 'Author',
        'version' => 'Version',
    ];

    /** @var array<string, ClassicTheme>|null */
    private static ?array $themes = null;

    /** @return array<string, ClassicTheme> keyed by directory name, in directory order */
    public static function all(): array
    {
        return self::$themes ??= self::scan();
    }

    public static function find(?string $dir): ?ClassicTheme
    {
        if ($dir === null || preg_match(self::DIR_PATTERN, $dir) !== 1) {
            return null;
        }

        return self::all()[$dir] ?? null;
    }

    public static function root(): string
    {
        return public_path('opSkinThemePlugin');
    }

    /** Drop the per-process memo (one scan per PHP-FPM request), for a test that adds or removes a package. */
    public static function forget(): void
    {
        self::$themes = null;
    }

    /** @return array<string, ClassicTheme> */
    private static function scan(): array
    {
        $themes = [];

        // Directories first, as OpenPNE 3 globbed them: "cerulean" sorts before "cerulean-light" by name, not by path.
        foreach (glob(self::root().'/*', GLOB_ONLYDIR) ?: [] as $dirPath) {
            $dir = basename($dirPath);
            $cssDir = $dirPath.'/css';
            $mainCss = $cssDir.'/main.css';
            if (preg_match(self::DIR_PATTERN, $dir) !== 1 || ! is_file($mainCss)) {
                continue;
            }
            $header = self::header($mainCss);
            // OpenPNE 3 listed a directory as a theme only when the header named it and its author.
            if ($header['name'] === '' || $header['author'] === '') {
                continue;
            }
            $themes[$dir] = new ClassicTheme(
                $dir,
                $header['name'],
                $header['description'],
                $header['author'],
                $header['version'],
                array_map(
                    static fn (string $path): string => 'opSkinThemePlugin/'.$dir.'/css/'.basename($path),
                    glob($cssDir.'/*.css') ?: [],
                ),
            );
        }

        return $themes;
    }

    /**
     * The file header OpenPNE 3's opThemeInfoParser read: one "Key: value" per line within the first 8 KiB,
     * the value cut at a closing comment.
     *
     * @return array{name: string, description: string, author: string, version: string}
     */
    private static function header(string $file): array
    {
        $data = str_replace("\r", "\n", (string) file_get_contents($file, false, null, 0, 8192));
        $header = [];
        foreach (self::HEADERS as $key => $label) {
            $header[$key] = preg_match('/^[ \t\/*#@]*'.preg_quote($label, '/').':(.*)$/mi', $data, $match) === 1
                ? trim((string) preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1]))
                : '';
        }

        return $header;
    }
}

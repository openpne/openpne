<?php

declare(strict_types=1);

namespace Tests\Unit\Views;

use PHPUnit\Framework\TestCase;

class StaticAssetVersionGuardTest extends TestCase
{
    /** The three variables are the layout's own, each filled by a helper that versions what it returns or names a route. */
    private const LINKED = '/\A"\{\{ (?:versioned_asset\(\'[^\']+\'\)|\$pluginCssUrl|\$customCssUrl|\$themeCssUrl) \}\}"\z/';

    public function test_every_script_and_stylesheet_a_view_links_is_versioned(): void
    {
        $found = [];

        foreach ($this->sources('resources/views') as $path => $source) {
            // A `->` inside a Blade echo would otherwise end the tag for the two patterns below.
            $source = (string) preg_replace_callback('/\{\{.*?\}\}|\{!!.*?!!\}/s', static fn (array $echo): string => str_replace('>', "\x01", $echo[0]), $source);
            preg_match_all('/<script\b[^>]*?\bsrc=("[^"]*"|[^\s>]+)/i', $source, $scripts);
            preg_match_all('/<link\b(?=[^>]*\brel=["\']?stylesheet\b)[^>]*?\bhref=("[^"]*"|[^\s>]+)/i', $source, $stylesheets);
            foreach ([...$scripts[1], ...$stylesheets[1]] as $value) {
                if (preg_match(self::LINKED, $value) !== 1) {
                    $found[$path][] = str_replace("\x01", '>', $value);
                }
            }
        }

        $this->assertSame([], $found);
    }

    public function test_no_script_or_stylesheet_path_is_passed_to_plain_asset(): void
    {
        $found = [];

        foreach ([...$this->sources('resources/views'), ...$this->sources('app')] as $path => $source) {
            if (preg_match_all('/(?<!versioned_)(?<!Vite::)asset\(\s*[\'"][^\'"]+\.(?:js|css)[\'"]/', $source, $matches) > 0) {
                $found[$path] = $matches[0];
            }
        }

        $this->assertSame([], $found);
    }

    /** @return array<string, string> path under the repository root => source */
    private function sources(string $dir): array
    {
        $root = dirname(__DIR__, 3);
        $sources = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $sources[str_replace($root.'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
            }
        }

        return $sources;
    }
}

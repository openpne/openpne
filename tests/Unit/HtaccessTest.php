<?php

namespace Tests\Unit;

use App\SiteCheck\SiteLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtaccessTest extends TestCase
{
    /**
     * What `AllowOverride FileInfo` lets a .htaccess use, sections included, less `RewriteOptions` (docs/internals/runtime.md, "Apache .htaccess files").
     * Apache reads directive names in any case, so they are compared lowercased.
     */
    private const FILE_INFO = [
        'RewriteEngine', 'RewriteCond', 'RewriteRule', 'IfModule', 'FilesMatch',
        'SetOutputFilter', 'RequestHeader', 'SetEnvIf', 'Header',
    ];

    /** @return array<string, array{string}> */
    public static function files(): array
    {
        return ['top' => ['.htaccess'], 'public' => ['public/.htaccess']];
    }

    #[DataProvider('files')]
    public function test_it_uses_only_directives_that_allow_override_file_info_permits(string $file): void
    {
        $directives = array_map(
            fn (string $line): string => strtolower(ltrim(strtok($line, " \t>"), '</')),
            $this->lines($file),
        );

        $this->assertNotSame([], $directives);
        $this->assertSame([], array_values(array_diff($directives, array_map('strtolower', self::FILE_INFO))));
    }

    public function test_the_top_file_refuses_every_request_outside_any_if_module(): void
    {
        $this->assertSame(['RewriteEngine On', 'RewriteRule ^ - [F]'], $this->lines('.htaccess'));
    }

    public function test_openpne_data_is_given_the_top_file_as_it_is(): void
    {
        $this->assertSame(file_get_contents(dirname(__DIR__, 2).'/.htaccess'), SiteLayout::DENY);
    }

    public function test_a_copy_denies_whatever_its_comments_and_line_endings(): void
    {
        $this->assertTrue(SiteLayout::denies("# An older comment\r\nRewriteEngine On\r\n\r\nRewriteRule ^ - [F]"));
        $this->assertFalse(SiteLayout::denies("RewriteEngine On\n# RewriteRule ^ - [F]\n"));
        $this->assertFalse(SiteLayout::denies(''));
    }

    public function test_public_sends_a_request_for_an_existing_directory_to_index_php(): void
    {
        $lines = $this->lines('public/.htaccess');
        $rule = array_search('RewriteRule ^ index.php [L]', $lines, true);

        $this->assertIsInt($rule);
        $this->assertSame('RewriteCond %{REQUEST_FILENAME} !-f', $lines[$rule - 1]);
        $this->assertStringStartsNotWith('RewriteCond', $lines[$rule - 2]);
    }

    /** @return list<string> */
    private function lines(string $file): array
    {
        $text = file_get_contents(dirname(__DIR__, 2).'/'.$file);
        $this->assertIsString($text);

        return array_values(array_filter(
            array_map('trim', explode("\n", $text)),
            fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
        ));
    }
}

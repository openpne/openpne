<?php

namespace Tests\Feature\Architecture;

use App\Support\Database\SearchCollation;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * A LIKE outside SearchCollation compares through the column's collation, which is not what members
 * searching expect (docs/internals/text-comparison.md, "Search and name order"). Every string literal
 * in `app/` with the word in it is caught, so a raw fragment, an operator argument and English prose
 * alike have to be argued for here.
 */
class SearchCollationSeamTest extends TestCase
{
    /** @var array<string, array<string, string>> file => a piece of the literal => why it may say "like" */
    private const ALLOWED = [
        'app/Console/Commands/ImageCacheCommand.php' => [
            "'like'" => 'a constant prefix of a MIME type, never member input',
        ],
        'app/Filament/Resources/Files/Tables/FilesTable.php' => [
            "'LIKE'" => 'a constant prefix of a MIME type, never member input',
            "'NOT LIKE'" => 'a constant prefix of a MIME type, never member input',
        ],
        'app/Console/Commands/PruneOwnerlessFilesCommand.php' => [
            "NOT LIKE 'admin!_%'" => 'a constant prefix of a generated file name',
        ],
        'app/Upgrade/SourceLocale.php' => [
            "LIKE 'ja%'" => 'reads an OpenPNE 3 table, which keeps its own collation',
            "LIKE 'en%'" => 'reads an OpenPNE 3 table, which keeps its own collation',
        ],
        'app/Upgrade/Steps/MemberUpgrade.php' => [
            "LIKE 'ja%'" => 'reads an OpenPNE 3 table, which keeps its own collation',
            "LIKE 'en%'" => 'reads an OpenPNE 3 table, which keeps its own collation',
        ],
        'app/Upgrade/Steps/MemberProfileUpgrade.php' => [
            "LIKE 'op_preset_%'" => 'reads an OpenPNE 3 table, which keeps its own collation',
            "LIKE \\'op_preset_%%\\'" => 'reads an OpenPNE 3 table, which keeps its own collation',
        ],
        'app/Upgrade/Steps/NavigationUpgrade.php' => [
            '`uri` LIKE' => 'reads an OpenPNE 3 table, which keeps its own collation',
        ],
        'app/Upgrade/Runner/ClassicThemeSelection.php' => [
            '`name` like ?' => 'reads an OpenPNE 3 table, which keeps its own collation',
        ],
        'app/Upgrade/Runner/NicePreflight.php' => [
            'W-LIKE-' => 'a warning code naming opLikePlugin\'s likes',
            'like(s)' => 'prose in a warning',
            'a like lands' => 'prose in a warning',
        ],
        'app/Upgrade/Runner/FileBinMigration.php' => [
            'looks like this' => 'prose in an error',
        ],
        'app/Compat/Parities/MemberRouteParity.php' => [
            'worded like the' => 'prose in a parity note',
            'and like OpenPNE 3' => 'prose in a parity note',
        ],
    ];

    /** Laravel's own LIKE helpers, which compare through the column's collation. */
    private const LIKE_METHODS = ['wherelike', 'orwherelike', 'wherenotlike', 'orwherenotlike'];

    public function test_no_like_is_written_outside_the_helper(): void
    {
        $offending = [];
        foreach ($this->sources() as $path => $tokens) {
            foreach (self::likes($tokens) as [$line, $text]) {
                if (! self::allowed($path, $text)) {
                    $offending[] = "{$path}:{$line} {$text}";
                }
            }
        }

        $this->assertSame([], $offending, 'match a member-typed term through SearchCollation, or list the literal with its reason');
    }

    public function test_every_allowed_literal_is_still_written(): void
    {
        foreach (self::ALLOWED as $path => $pieces) {
            $texts = array_column(iterator_to_array(self::likes(token_get_all((string) file_get_contents(base_path($path)))), false), 1);
            foreach (array_keys($pieces) as $piece) {
                $this->assertNotEmpty(
                    array_filter($texts, fn (string $text): bool => str_contains($text, $piece)),
                    "{$path} no longer writes {$piece}; drop it from the list",
                );
            }
        }
    }

    /**
     * The panel's per-component default does not reach these: a table-wide searchable([...]) always
     * passes Filament no case folding, and MorphToSelect's types are not configurable components.
     */
    public function test_no_filament_search_bypasses_the_panel_default(): void
    {
        $offending = [];
        foreach ($this->sources() as $path => $tokens) {
            foreach ($tokens as $i => $token) {
                if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                $next = self::nextMeaningful($tokens, $i);
                $afterNext = $next === null ? null : self::nextMeaningful($tokens, $next);
                $isArraySearchable = strcasecmp($token[1], 'searchable') === 0
                    && $next !== null && $tokens[$next] === '('
                    && $afterNext !== null && $tokens[$afterNext] === '[';
                if ($isArraySearchable || class_basename($token[1]) === 'MorphToSelect') {
                    $offending[] = "{$path}:{$token[2]} {$token[1]}";
                }
            }
        }

        $this->assertSame([], $offending, 'force case folding on this search the way the panel defaults do, then extend this test');
    }

    private static function allowed(string $path, string $text): bool
    {
        foreach (array_keys(self::ALLOWED[$path] ?? []) as $piece) {
            if (str_contains($text, $piece)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $tokens
     * @return iterable<array{int, string}> line and text of each literal or call that says "like"
     */
    private static function likes(array $tokens): iterable
    {
        foreach ($tokens as $token) {
            if (! is_array($token)) {
                continue;
            }
            [$id, $text, $line] = $token;
            $isLiteral = in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
                && preg_match('/\blike\b/i', $text) === 1;
            $isMethod = $id === T_STRING && in_array(strtolower($text), self::LIKE_METHODS, true);
            if ($isLiteral || $isMethod) {
                yield [$line, $text];
            }
        }
    }

    /** @param  array<int, mixed>  $tokens */
    private static function nextMeaningful(array $tokens, int $i): ?int
    {
        for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
            if (! is_array($tokens[$j]) || ! in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $j;
            }
        }

        return null;
    }

    /** @return iterable<string, array<int, mixed>> */
    private function sources(): iterable
    {
        $helper = (string) (new ReflectionClass(SearchCollation::class))->getFileName();
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || $file->getPathname() === $helper) {
                continue;
            }
            yield substr($file->getPathname(), strlen(base_path()) + 1) => token_get_all((string) file_get_contents($file->getPathname()));
        }
    }
}

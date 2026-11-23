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
            " LIKE 'op!_preset!_%' ESCAPE '!')))" => 'reads an OpenPNE 3 table, which keeps its own collation',
            "NOT LIKE 'op!_preset!_%' ESCAPE '!')" => 'reads an OpenPNE 3 table, which keeps its own collation',
            "LIKE \\'op!_preset!_%%\\' ESCAPE \\'!\\'" => 'reads an OpenPNE 3 table, which keeps its own collation',
        ],
        'app/Upgrade/Steps/NavigationUpgrade.php' => [
            "`uri` LIKE '/%'" => 'reads an OpenPNE 3 table, which keeps its own collation',
            "`uri` LIKE '@%'" => 'reads an OpenPNE 3 table, which keeps its own collation',
        ],
        'app/Upgrade/Runner/ClassicThemeSelection.php' => [
            '`name` like ?' => 'reads an OpenPNE 3 table, which keeps its own collation',
        ],
    ];

    /** @var array<string, array<string, string>> file => a piece of English or a code that says "like" => what it is */
    private const PROSE = [
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

    /** @var array<string, array<string, string>> file => the call or component => why Filament may search there by itself */
    private const FILAMENT_ALLOWED = [
        'app/Filament/Resources/Groups/Schemas/GroupForm.php' => [
            'searchable' => 'the options are a PHP array, which the browser filters',
        ],
    ];

    /** Components that search the database with SQL of Filament's own and take no query. */
    private const FILAMENT_SEARCHERS = ['MorphToSelect', 'AttachAction', 'AssociateAction', 'SelectColumn', 'TextConstraint', 'RelationshipConstraint', 'IsRelatedToOperator'];

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

    /** A SQL piece covering two literals would let a new LIKE in the same file pass as the listed one. */
    public function test_every_allowed_piece_is_still_written(): void
    {
        foreach ([[self::ALLOWED, true], [self::PROSE, false]] as [$list, $once]) {
            foreach ($list as $path => $pieces) {
                $texts = array_column(iterator_to_array(self::likes(token_get_all((string) file_get_contents(base_path($path)))), false), 1);
                foreach (array_keys($pieces) as $piece) {
                    $covered = count(array_filter($texts, fn (string $text): bool => str_contains($text, $piece)));
                    $this->assertTrue($once ? $covered === 1 : $covered > 0, "{$path}: {$piece} names {$covered} literals; drop it if none is left, lengthen it if it names more");
                }
            }
        }
    }

    /** Filament's own search SQL compares through the column's collation, or lowers the term in PHP and the column in SQL, which disagree for some letters. */
    public function test_no_filament_search_builds_its_own_sql(): void
    {
        $offending = [];
        $used = [];
        foreach ($this->sources() as $path => $tokens) {
            foreach (self::filamentSearches($tokens) as [$line, $name]) {
                if (isset(self::FILAMENT_ALLOWED[$path][$name])) {
                    $used[$path][$name] = ($used[$path][$name] ?? 0) + 1;
                } else {
                    $offending[] = "{$path}:{$line} {$name}";
                }
            }
        }

        $this->assertSame([], $offending, 'search through searchable(query: CollatedSearch::on(...)), or list the search with its reason');
        foreach (self::FILAMENT_ALLOWED as $path => $names) {
            foreach (array_keys($names) as $name) {
                $this->assertSame(1, $used[$path][$name] ?? 0, "{$path}: {$name} should name one search; drop it if none is left");
            }
        }
    }

    private static function allowed(string $path, string $text): bool
    {
        foreach ([...array_keys(self::ALLOWED[$path] ?? []), ...array_keys(self::PROSE[$path] ?? [])] as $piece) {
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

    /**
     * @param  array<int, mixed>  $tokens
     * @return iterable<array{int, string}> line and name of a searchable() call without query:, or of a component that searches by itself
     */
    private static function filamentSearches(array $tokens): iterable
    {
        foreach ($tokens as $i => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $name = class_basename($token[1]);
            if (in_array($name, self::FILAMENT_SEARCHERS, true)) {
                yield [$token[2], $name];
            }
            $open = self::nextMeaningful($tokens, $i);
            if (strcasecmp($token[1], 'searchable') === 0 && $open !== null && $tokens[$open] === '(' && ! self::passesQuery($tokens, $open)) {
                yield [$token[2], 'searchable'];
            }
        }
    }

    /** @param  array<int, mixed>  $tokens */
    private static function passesQuery(array $tokens, int $open): bool
    {
        $depth = 0;
        for ($j = $open, $n = count($tokens); $j < $n; $j++) {
            $depth += match ($tokens[$j]) {
                '(', '[' => 1,
                ')', ']' => -1,
                default => 0,
            };
            if ($depth === 0) {
                return false;
            }
            $colon = self::nextMeaningful($tokens, $j);
            if ($depth === 1 && is_array($tokens[$j]) && $tokens[$j][1] === 'query' && $colon !== null && $tokens[$colon] === ':') {
                return true;
            }
        }

        return false;
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

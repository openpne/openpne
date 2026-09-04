<?php

namespace Tests\Feature\Frontend;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Weight occurrences under resources/js are counted per file against a budget (docs/internals/typography.md):
 * a weight in a file with no budget, a count above its budget, or a count below it fails. A budgeted
 * file is not a skipped file; owners get an exact count because they also hold ordinary text.
 */
class FontWeightGuardTest extends TestCase
{
    /**
     * Every weight utility except `font-normal` (400 is the rule) plus the escape hatches that compile
     * to a font-weight; the bare `font-(--x)` form counts because Tailwind 4 emits `font-weight: var(--x)`
     * for it. Family utilities share the prefix and are deliberately not matched, so the custom-property
     * branch requires `--` or `weight:` right after the paren.
     */
    private const WEIGHT_CLASS = '/\bfont-(?:thin|extralight|extrabold|semibold|medium|light|black|bold)\b|\bfont-\[|\[font-weight:|\bfont-\((?:weight:)?--/';

    /** @var array<string, int> */
    private const ROLE_OWNERS = [
        // headingVariants: the one place a heading's weight is written.
        'components/ui/heading.tsx' => 1,
        // unreadTextClass: the one place unread is expressed.
        'components/unread.tsx' => 1,
    ];

    /** @var array<string, int> */
    private const OUT_OF_SCOPE = [
        'components/brand-mark.tsx' => 1,
        'components/brand-name.tsx' => 1,
        'components/initial-badge.tsx' => 1,
    ];

    /**
     * Weight a component kept because 400 failed the on-device check. Record the route and state it
     * was judged in and which of the three axes it failed — label/value hierarchy, current-state
     * legibility, or primary/secondary distinction — so the entry can be re-judged rather than
     * inherited on trust.
     *
     * @var array<string, int>
     */
    private const EARNED_EXCEPTIONS = [];

    /**
     * Files still carrying decorative weight: a migration ledger, not a second exceptions list, and
     * empty is its finished state.
     *
     * @var array<string, int>
     */
    private const DEBT_BASELINE = [];

    /** @return array<string, int> */
    private function budgets(): array
    {
        return self::ROLE_OWNERS + self::OUT_OF_SCOPE + self::EARNED_EXCEPTIONS + self::DEBT_BASELINE;
    }

    public static function weightOccurrences(string $contents): int
    {
        return preg_match_all(self::WEIGHT_CLASS, $contents);
    }

    /**
     * `.ts` counts as UI source, not just `.tsx`: `compose/editor-extensions.ts` holds the class string
     * ProseMirror's editable is rendered with. Only `.test.ts` files are excluded; a `.test.tsx` fixture
     * is scanned like any component.
     */
    private function occurrences(): array
    {
        $base = resource_path('js');
        $counts = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $name = $file->getFilename();
            if (! $file->isFile() || ! (str_ends_with($name, '.ts') || str_ends_with($name, '.tsx')) || str_ends_with($name, '.test.ts')) {
                continue;
            }
            $rel = str_replace($base.'/', '', $file->getPathname());
            $counts[$rel] = self::weightOccurrences((string) file_get_contents($file->getPathname()));
        }
        ksort($counts);

        return $counts;
    }

    /**
     * What the pattern must catch and must not. The budgets below are only as good as this: a weight
     * the regex cannot see costs nothing to add anywhere in the tree.
     *
     * @return array<string, array{string, int}>
     */
    public static function weightClassCases(): array
    {
        return [
            'thin' => ['<span className="font-thin">', 1],
            'extralight' => ['<span className="font-extralight">', 1],
            'light' => ['<span className="font-light">', 1],
            'medium' => ['<span className="font-medium">', 1],
            'semibold' => ['<span className="font-semibold">', 1],
            'bold' => ['<span className="font-bold">', 1],
            'extrabold' => ['<span className="font-extrabold">', 1],
            'black' => ['<span className="font-black">', 1],
            'arbitrary value' => ['<span className="font-[550]">', 1],
            'arbitrary property' => ['<span className="[font-weight:700]">', 1],
            'custom property, typed' => ['<span className="font-(weight:--my-fw)">', 1],
            // The bare custom-property form is a weight, not a family: Tailwind emits
            // `font-weight: var(--my-fw)` for it.
            'custom property, shorthand' => ['<span className="font-(--my-fw)">', 1],
            'behind a variant' => ['<span className="lg:font-extrabold">', 1],
            'custom property behind a variant' => ['<span className="lg:font-(--my-fw)">', 1],
            'several in one string' => ['"font-medium sm:font-bold"', 2],
            // 400 is the rule, so spelling it out is not a violation.
            'normal' => ['<span className="font-normal">', 0],
            'family utilities' => ['<span className="font-sans font-mono font-serif">', 0],
            'family as a custom property' => ['<span className="font-(family-name:--my-font)">', 0],
            'a word ending in the utility name' => ['<span className="not-font-bolder">', 0],
        ];
    }

    #[DataProvider('weightClassCases')]
    public function test_pattern_sees_every_weight_utility(string $markup, int $expected): void
    {
        $this->assertSame($expected, self::weightOccurrences($markup));
    }

    public function test_no_font_weight_outside_a_budgeted_file(): void
    {
        $budgets = $this->budgets();
        $offenders = [];
        foreach ($this->occurrences() as $rel => $count) {
            if ($count > 0 && ! array_key_exists($rel, $budgets)) {
                $offenders[] = $rel;
            }
        }

        $this->assertSame([], $offenders, 'font-weight class in a file with no budget. Weight names a region (use Heading) or marks unread; everything else is 400: '.implode(', ', $offenders));
    }

    public function test_no_file_exceeds_its_budget(): void
    {
        $counts = $this->occurrences();
        $over = [];
        foreach ($this->budgets() as $rel => $budget) {
            $count = $counts[$rel] ?? 0;
            if ($count > $budget) {
                $over[] = "{$rel} ({$count} > {$budget})";
            }
        }

        $this->assertSame([], $over, 'More font-weight classes than budgeted — a file on the debt list may only shrink: '.implode(', ', $over));
    }

    public function test_budgets_are_not_stale(): void
    {
        $counts = $this->occurrences();
        $stale = [];
        foreach ($this->budgets() as $rel => $budget) {
            if (! is_file(resource_path('js/'.$rel))) {
                $stale[] = "{$rel} (file gone)";

                continue;
            }
            $count = $counts[$rel] ?? 0;
            if ($count < $budget) {
                $stale[] = "{$rel} ({$count} < {$budget})";
            }
        }

        $this->assertSame([], $stale, 'Budget higher than the actual count — lower it in the same PR that removed the weight, so the ratchet stays the source of truth: '.implode(', ', $stale));
    }
}

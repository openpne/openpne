<?php

namespace Tests\Feature\Frontend;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Guards the Modern surface's emphasis rule: font weight has exactly two jobs — naming a region
 * (headings) and marking unread state — and every other text is 400, so a row's hierarchy comes from
 * size and color. Decorative weight is what made the dashboard read as "bold everywhere".
 *
 * Files are budgeted, never skipped. An owner file is allowed the weights its role implementation
 * spells and no more, because the ones that hold a role also hold ordinary text: top-nav carries the
 * bar label next to a guest sign-in link, auth-layout the brand beside a page heading. Waving a whole
 * file through would let any later weight in it stay green forever — the hole this design exists to
 * close.
 *
 * Four budgets, differing only in whether they are meant to reach zero:
 *  - ROLE_OWNERS      the canonical implementation of an allowed role. Permanent.
 *  - OUT_OF_SCOPE     identity marks: the brand lockup and the initial badge. Not body text, so the
 *                     rule does not reach them. Permanent.
 *  - EARNED_EXCEPTIONS weights kept after a component was tried at 400 and the on-device comparison
 *                     rejected it. Each entry records what failed. Permanent once earned, empty until
 *                     something earns a place here.
 *  - DEBT_BASELINE    not yet migrated. Only ever shrinks; empty ends the campaign.
 *
 * The count is a ratchet, and a ratchet on counts cannot see a removal and an addition inside one
 * file cancelling out. Occurrence fingerprints would close that; the budget is the cheaper guard for
 * a campaign whose debt is actively draining. Raise it if a net-zero swap ever slips through.
 *
 * Out of reach entirely, so it stays a review question rather than a test: semantic <strong> in
 * anything rendered through RichBody (the author's emphasis, not ours — member bodies as well as the
 * admin-written login message and policy pages), plain CSS in app.css, and inline styles.
 */
class FontWeightGuardTest extends TestCase
{
    /**
     * Every Tailwind weight utility except `font-normal` — 400 is the rule, so naming it is not a
     * violation — plus every escape hatch that compiles to a font-weight:
     *
     *   font-[550]                   arbitrary value
     *   [font-weight:700]            arbitrary property
     *   font-(weight:--x)            custom property, typed
     *   font-(--x)                   custom property, shorthand — weight is what the bare form means
     *
     * Verified against the installed Tailwind (4.3.3) rather than assumed: the last two both emit
     * `font-weight: var(--x)`.
     *
     * Family utilities share the prefix and are deliberately not matched — `font-sans`, and
     * `font-(family-name:--x)`, which is why the custom-property branch requires `--` or `weight:`
     * right after the paren instead of accepting any `font-(`.
     */
    private const WEIGHT_CLASS = '/\bfont-(?:thin|extralight|extrabold|semibold|medium|light|black|bold)\b|\bfont-\[|\[font-weight:|\bfont-\((?:weight:)?--/';

    /** @var array<string, int> */
    private const ROLE_OWNERS = [
        // headingVariants: the one place a heading's weight is written.
        'components/ui/heading.tsx' => 1,
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
     * Files still carrying decorative weight, with the count each may have. Shrinks as the campaign
     * lands; the disposition and owning PR for every one of these is in
     * worklog/current/modern-emphasis-inventory.md (private workspace).
     *
     * @var array<string, int>
     */
    private const DEBT_BASELINE = [
        'components/action-fab.tsx' => 1,
        'components/avatar-menu.tsx' => 1,
        'components/compose/input-method-menu.tsx' => 1,
        'components/compose/rich-text-editor.tsx' => 2,
        'components/confirm-dialog.tsx' => 3,
        'components/current-images-field.tsx' => 1,
        'components/entry-row.tsx' => 2,
        'components/images-field.tsx' => 1,
        'components/link-card.tsx' => 1,
        'components/markdown-preview.tsx' => 1,
        'components/nav-items.tsx' => 1,
        'components/page-tabs.tsx' => 1,
        'components/top-nav.tsx' => 1,
        'components/ui/button.tsx' => 1,
        'components/ui/dropdown-menu.tsx' => 1,
        'components/ui/field.tsx' => 1,
        'components/ui/label.tsx' => 1,
        'components/ui/otp-input.tsx' => 1,
        'components/ui/radio-card.tsx' => 1,
        'components/unread-pill.tsx' => 1,
        'layouts/auth-layout.tsx' => 1,
        'pages/auth/register-complete.tsx' => 1,
        'pages/block/list.tsx' => 1,
        'pages/community/edit.tsx' => 1,
        'pages/community/event/show.tsx' => 1,
        'pages/community/search.tsx' => 1,
        'pages/community/topic/show.tsx' => 1,
        'pages/diary/archive-grid.tsx' => 1,
        'pages/diary/show.tsx' => 3,
        'pages/member/avatar.tsx' => 2,
        'pages/member/config.tsx' => 1,
        'pages/member/config/mfa.tsx' => 2,
        'pages/member/config/notifications.tsx' => 3,
        'pages/member/edit-profile.tsx' => 1,
        'pages/member/profile-field-input.tsx' => 1,
        'pages/member/search.tsx' => 4,
        'pages/member/show.tsx' => 2,
        'pages/message/compose.tsx' => 1,
        'pages/message/edit.tsx' => 1,
        'pages/message/index.tsx' => 1,
        'pages/message/show.tsx' => 2,
        'pages/notifications/index.tsx' => 1,
        'pages/timeline/new.tsx' => 1,
        'pages/timeline/post-card.tsx' => 1,
        'pages/timeline/show.tsx' => 2,
    ];

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
     * Weight occurrences per source file under resources/js, keyed by path relative to it.
     *
     * `.ts` counts as UI source, not just `.tsx`: `compose/editor-extensions.ts` holds the class
     * string ProseMirror's editable is rendered with, so a scan limited to components would read
     * past it. Node test files are excluded — their fixtures are not shipped markup.
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

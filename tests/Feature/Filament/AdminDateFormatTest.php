<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\CommunityEvents\Pages\ListCommunityEvents;
use App\Models\AdminUser;
use App\Models\CommunityEvent;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin panel's date contract from docs/internals/datetime.md: one sortable format, set as
 * Filament's default rather than per column, and civil dates kept apart from instants.
 */
class AdminDateFormatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    /**
     * Rendered, not asserted against the configuration: this is what proves the default actually
     * reaches a column, which reading the setter back would not.
     */
    public function test_an_instant_renders_as_sortable_digits_without_seconds(): void
    {
        CommunityEvent::factory()->create([
            'created_at' => '2026-08-10 09:05:16',
            'open_date' => '2026-08-14',
        ]);

        $rendered = Livewire::test(ListCommunityEvents::class)->assertSuccessful()->html();

        $this->assertStringContainsString('2026-08-10 09:05', $rendered);
        $this->assertStringNotContainsString('2026-08-10 09:05:16', $rendered, 'seconds reached a display');
        // Filament's own default, which the panel replaces.
        $this->assertStringNotContainsString('Aug 10, 2026', $rendered);
    }

    /** An event's open date is a calendar day; giving it a time would invent one. */
    public function test_a_civil_date_renders_without_a_time(): void
    {
        CommunityEvent::factory()->create([
            'created_at' => '2026-08-10 09:05:16',
            'open_date' => '2026-08-14',
        ]);

        $rendered = Livewire::test(ListCommunityEvents::class)->assertSuccessful()->html();

        $this->assertStringContainsString('2026-08-14', $rendered);
        $this->assertStringNotContainsString('2026-08-14 00:00', $rendered);
    }

    /**
     * Every Filament method that formats a date, split by what the contract allows. Tooltips included:
     * they render the same value in the same panel.
     */
    private const MUST_TAKE_THE_DEFAULT = ['date', 'dateTime', 'time', 'dateTooltip', 'dateTimeTooltip', 'timeTooltip'];

    private const NOT_IN_ADMIN = [
        // Relative time cannot be filtered by period or compared between two rows.
        'since' => 'relative time has no place in admin',
        'sinceTooltip' => 'relative time has no place in admin',
        // The iso* family renders the locale's narrative form, which is the shape admin deliberately
        // avoids — use dateTime() and take the panel default.
        'isoDate' => 'renders the localized form instead of sortable digits',
        'isoDateTime' => 'renders the localized form instead of sortable digits',
        'isoTime' => 'renders the localized form instead of sortable digits',
        'isoDateTooltip' => 'renders the localized form instead of sortable digits',
        'isoDateTimeTooltip' => 'renders the localized form instead of sortable digits',
        'isoTimeTooltip' => 'renders the localized form instead of sortable digits',
    ];

    /**
     * The format lives in one place, so a screen added later inherits it. A column passing its own would
     * still render — and would drift the moment the default changes — which no rendering test elsewhere
     * would catch.
     *
     * Tokenized rather than matched as text: an argument can arrive as a literal, a named argument, a
     * constant or a closure, and a pattern written for one of those spellings quietly passes the rest.
     * What is checked is only whether the parentheses are empty.
     */
    public function test_no_column_carries_a_format_of_its_own(): void
    {
        $offenders = [];

        foreach ($this->filamentSources() as $path => $source) {
            foreach ($this->dateCalls($source) as [$method, $hasArguments]) {
                if (isset(self::NOT_IN_ADMIN[$method])) {
                    $offenders[] = "{$path}: ->{$method}() — ".self::NOT_IN_ADMIN[$method];
                } elseif ($hasArguments && in_array($method, self::MUST_TAKE_THE_DEFAULT, true)) {
                    $offenders[] = "{$path}: ->{$method}(…) — drop the argument and take the panel default";
                }
            }
        }

        $this->assertSame([], $offenders, "docs/internals/datetime.md, Admin:\n".implode("\n", $offenders));
    }

    /** @return array<string, string> relative path => source */
    private function filamentSources(): array
    {
        $sources = [];

        foreach (File::allFiles(app_path('Filament')) as $file) {
            if ($file->getExtension() === 'php') {
                $sources[$file->getRelativePathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $sources;
    }

    /**
     * Every `->method(` in a source, with whether its parentheses hold anything.
     *
     * @return list<array{0: string, 1: bool}>
     */
    private function dateCalls(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            fn ($token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $calls = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_OBJECT_OPERATOR) {
                continue;
            }

            $name = $tokens[$index + 1] ?? null;
            $open = $tokens[$index + 2] ?? null;

            if (! is_array($name) || $name[0] !== T_STRING || $open !== '(') {
                continue;
            }

            $calls[] = [$name[1], ($tokens[$index + 3] ?? null) !== ')'];
        }

        return $calls;
    }
}

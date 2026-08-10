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
     * The format lives in one place, so a screen added later inherits it. A column passing its own would
     * still render — and would drift the moment the default changes — which no rendering test elsewhere
     * would catch.
     */
    public function test_no_column_carries_a_format_of_its_own(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path('Filament')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all('/->(?:date|dateTime|time|isoDate|isoDateTime|dateTimeTooltip)\(\s*[\'"]/', (string) file_get_contents($file->getPathname()), $matches);

            if ($matches[0] !== []) {
                $offenders[] = $file->getRelativePathname().': '.implode(', ', $matches[0]);
            }
        }

        $this->assertSame([], $offenders, "Drop the format and let the panel default apply:\n".implode("\n", $offenders));
    }
}

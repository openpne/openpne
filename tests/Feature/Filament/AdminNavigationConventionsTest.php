<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sidebar conventions from docs/internals/admin-navigation.md: five groups, every entry placed
 * and sorted explicitly, no two entries reading alike.
 */
class AdminNavigationConventionsTest extends TestCase
{
    use RefreshDatabase;

    /** The groups AdminPanelProvider registers, by translation key. */
    private const GROUP_KEYS = ['Members', 'Content', 'Settings', 'Appearance (Classic)', 'System'];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    /**
     * Built from what the panel registers rather than from getNavigation(), which only returns what
     * the current request may see: InviteMembers hides itself on a closed registration mode and the
     * Classic appearance screens hide themselves on modern_only, so a canAccess()-derived set would
     * let those screens drift out of contract unchecked.
     *
     * @return list<class-string>
     */
    private function navigableScreens(): array
    {
        $panel = Filament::getCurrentPanel();

        $screens = array_filter(
            [...array_values($panel->getPages()), ...array_values($panel->getResources())],
            // Filament's own Dashboard sits above the groups by design; screens like SecuritySettings
            // (reached from the user menu) opt out of the sidebar altogether.
            fn (string $screen): bool => $screen !== Dashboard::class && $screen::shouldRegisterNavigation(),
        );

        $this->assertNotEmpty($screens, 'The admin panel registers navigable screens to check.');

        return array_values($screens);
    }

    public function test_every_screen_sits_in_a_registered_group(): void
    {
        $groups = array_map(fn (string $key): string => __($key), self::GROUP_KEYS);

        foreach ($this->navigableScreens() as $screen) {
            $this->assertContains(
                $screen::getNavigationGroup(),
                $groups,
                "{$screen} must sit in one of the registered navigation groups.",
            );
        }
    }

    public function test_every_screen_declares_its_sort(): void
    {
        foreach ($this->navigableScreens() as $screen) {
            $this->assertNotNull(
                $screen::getNavigationSort(),
                "{$screen} must declare \$navigationSort — an unsorted entry falls to discovery order.",
            );
        }
    }

    public function test_sort_is_unique_within_a_group(): void
    {
        $taken = [];

        foreach ($this->navigableScreens() as $screen) {
            $group = (string) $screen::getNavigationGroup();
            $sort = $screen::getNavigationSort();

            $this->assertArrayNotHasKey(
                $sort,
                $taken[$group] ?? [],
                "{$screen} takes sort {$sort} in \"{$group}\", already held by ".($taken[$group][$sort] ?? '').'.',
            );

            $taken[$group][$sort] = $screen;
        }
    }

    /**
     * Compared on the rendered label, not the key: %term% substitution and the two locales both
     * collapse distinct keys onto one string, and it is the rendered string an operator scans.
     */
    public function test_labels_are_unique_in_every_locale(): void
    {
        foreach (['en', 'ja'] as $locale) {
            app()->setLocale($locale);

            $seen = [];

            foreach ($this->navigableScreens() as $screen) {
                $label = $screen::getNavigationLabel();

                $this->assertStringNotContainsString(
                    '%',
                    $label,
                    "{$screen} renders the {$locale} label \"{$label}\": a placeholder that is not a registered term survives rendering.",
                );

                $this->assertArrayNotHasKey(
                    $label,
                    $seen,
                    "{$screen} renders the {$locale} label \"{$label}\", already used by ".($seen[$label] ?? '').'.',
                );

                $seen[$label] = $screen;
            }
        }
    }

    public function test_settings_group_labels_name_their_subject(): void
    {
        foreach (['en' => ' settings', 'ja' => '設定'] as $locale => $suffix) {
            app()->setLocale($locale);

            $settings = __('Settings');

            foreach ($this->navigableScreens() as $screen) {
                if ($screen::getNavigationGroup() !== $settings) {
                    continue;
                }

                $this->assertStringEndsWith(
                    $suffix,
                    $screen::getNavigationLabel(),
                    "{$screen} must be labelled \"<Subject> settings\".",
                );
            }
        }
    }
}

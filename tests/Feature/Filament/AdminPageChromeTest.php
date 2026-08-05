<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Features\Auth\RegistrationMode;
use App\Filament\Resources\Pages\ListPage;
use App\Models\AdminUser;
use App\Support\SnsSettingKey;
use App\Support\SurfaceMode;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The page-chrome conventions from docs/internals/admin-navigation.md: a list page carries no
 * breadcrumbs, and no section heading repeats the page title.
 */
class AdminPageChromeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');

        // Open the two gates that would otherwise drop screens from this sweep: InviteMembers needs a
        // mode that permits admin invites, the Appearance (Classic) screens need Classic served.
        $this->setSnsSetting(SnsSettingKey::RegistrationMode, RegistrationMode::Invite->value);
        $this->setSnsSetting(SnsSettingKey::SurfaceMode, SurfaceMode::ClassicDefault->value);
    }

    public function test_every_resource_list_page_extends_the_breadcrumbless_base(): void
    {
        $checked = 0;

        foreach (Filament::getCurrentPanel()->getResources() as $resource) {
            foreach ($resource::getPages() as $registration) {
                $page = $registration->getPage();

                if (! is_subclass_of($page, ListRecords::class)) {
                    continue;
                }

                $this->assertTrue(
                    is_subclass_of($page, ListPage::class),
                    "{$page} must extend ".ListPage::class.', which drops the depth-1 self-referential breadcrumb.',
                );

                $this->assertSame(
                    ListPage::class,
                    (new \ReflectionMethod($page, 'getBreadcrumbs'))->getDeclaringClass()->getName(),
                    "{$page} must not override getBreadcrumbs() — that would reintroduce the trail the base removes.",
                );

                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked, 'The admin panel registers list pages to check.');
    }

    /**
     * Read off the rendered page rather than the schema, so this holds whatever component grew the
     * heading. Both locales, because %term% substitution can collapse a distinct key onto the title.
     */
    public function test_no_heading_repeats_the_page_title(): void
    {
        foreach (['en', 'ja'] as $locale) {
            app()->setLocale($locale);

            foreach (Filament::getCurrentPanel()->getPages() as $page) {
                $this->assertTrue($page::canAccess(), "{$page} must be reachable for this sweep to cover it.");

                $component = Livewire::test($page);
                $title = trim((string) $component->instance()->getTitle());

                $this->assertSame(
                    1,
                    count(array_keys($this->headingsIn($component->html()), $title, true)),
                    "{$page} must name \"{$title}\" in exactly one heading — its own, not a section repeating it.",
                );
            }
        }
    }

    /**
     * The text of every heading element on the page, whitespace-collapsed and unescaped so it compares
     * against a raw title.
     *
     * @return list<string>
     */
    private function headingsIn(string $html): array
    {
        preg_match_all('/<(h[1-6])\b[^>]*>(.*?)<\/\1>/s', $html, $matches, PREG_SET_ORDER);

        return array_map(
            fn (array $match): string => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($match[2])))),
            $matches,
        );
    }
}
